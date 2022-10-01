<?php

declare(strict_types=1);

namespace muqsit\arithmexp;

use InvalidArgumentException;
use muqsit\arithmexp\expression\ConstantRegistry;
use muqsit\arithmexp\expression\Expression;
use muqsit\arithmexp\expression\token\ExpressionToken;
use muqsit\arithmexp\expression\token\FunctionCallExpressionToken;
use muqsit\arithmexp\expression\token\NumericLiteralExpressionToken;
use muqsit\arithmexp\expression\token\VariableExpressionToken;
use muqsit\arithmexp\function\FunctionRegistry;
use muqsit\arithmexp\operator\binary\BinaryOperatorRegistry;
use muqsit\arithmexp\operator\unary\UnaryOperatorRegistry;
use muqsit\arithmexp\token\BinaryOperatorToken;
use muqsit\arithmexp\token\FunctionCallArgumentSeparatorToken;
use muqsit\arithmexp\token\FunctionCallToken;
use muqsit\arithmexp\token\IdentifierToken;
use muqsit\arithmexp\token\LeftParenthesisToken;
use muqsit\arithmexp\token\NumericLiteralToken;
use muqsit\arithmexp\token\RightParenthesisToken;
use muqsit\arithmexp\token\Token;
use muqsit\arithmexp\token\UnaryOperatorToken;
use RuntimeException;
use function array_key_last;
use function array_map;
use function array_shift;
use function array_splice;
use function array_unshift;
use function assert;
use function count;
use function is_array;
use function substr;

final class Parser{

	public static function createDefault() : self{
		$binary_operator_registry = BinaryOperatorRegistry::createDefault();
		$unary_operator_registry = UnaryOperatorRegistry::createDefault();
		return new self(
			$binary_operator_registry,
			$unary_operator_registry,
			ConstantRegistry::createDefault(),
			FunctionRegistry::createDefault(),
			Scanner::createDefault($binary_operator_registry, $unary_operator_registry)
		);
	}

	public function __construct(
		private BinaryOperatorRegistry $binary_operator_registry,
		private UnaryOperatorRegistry $unary_operator_registry,
		private ConstantRegistry $constant_registry,
		private FunctionRegistry $function_registry,
		private Scanner $scanner
	){}

	public function getBinaryOperatorRegistry() : BinaryOperatorRegistry{
		return $this->binary_operator_registry;
	}

	public function getUnaryOperatorRegistry() : UnaryOperatorRegistry{
		return $this->unary_operator_registry;
	}

	public function getConstantRegistry() : ConstantRegistry{
		return $this->constant_registry;
	}

	public function getFunctionRegistry() : FunctionRegistry{
		return $this->function_registry;
	}

	/**
	 * Parses a given mathematical expression for runtime evaluation.
	 * This method precomputes the expression, deferring runtime evaluation of
	 * segments of the mathematical expression to the parser.
	 *
	 * @param string $expression
	 * @return Expression
	 * @throws ParseException
	 */
	public function parse(string $expression) : Expression{
		return $this->parseExpression($expression)->precomputed();
	}

	/**
	 * Parses a given mathematical expression for runtime evaluation.
	 *
	 * @param string $expression
	 * @return Expression
	 * @throws ParseException
	 */
	public function parseExpression(string $expression) : Expression{
		$tokens = $this->scanner->scan($expression);
		$this->deparenthesizeTokens($expression, $tokens);

		if(count($tokens) === 0){
			throw new ParseException("Cannot parse empty expression \"{$expression}\"");
		}

		$this->groupFunctionCallTokens($tokens);
		$this->groupUnaryOperatorTokens($expression, $tokens);
		$this->groupBinaryOperations($expression, $tokens);
		$this->transformFunctionCallTokens($expression, $tokens);

		if(count($tokens) > 1){
			$token = $tokens[1];
			while(is_array($token)){
				$token = $token[0];
			}
			throw new ParseException("Unexpected {$token->getType()->getName()} token encountered at \"" . substr($expression, $token->getStartPos(), $token->getEndPos() - $token->getStartPos()) . "\" ({$token->getStartPos()}:{$token->getEndPos()}) in \"{$expression}\"");
		}

		$this->convertTokenTreeToPostfixTokenTree($tokens);
		return new Expression($expression, array_map(function(Token $token) : ExpressionToken{
			if($token instanceof BinaryOperatorToken){
				$operator = $this->binary_operator_registry->get($token->getOperator());
				return new FunctionCallExpressionToken("BO<{$operator->getSymbol()}>", 2, $operator->getOperator(), true);
			}
			if($token instanceof FunctionCallToken){
				$name = $token->getFunction();
				$function = $this->function_registry->get($name);
				return new FunctionCallExpressionToken($name, $token->getArgumentCount(), $function->closure, $function->deterministic);
			}
			if($token instanceof IdentifierToken){
				$label = $token->getLabel();
				$constant_value = $this->constant_registry->registered[$label] ?? null;
				return $constant_value !== null ? new NumericLiteralExpressionToken($constant_value) : new VariableExpressionToken($label);
			}
			if($token instanceof NumericLiteralToken){
				return new NumericLiteralExpressionToken($token->getValue());
			}
			if($token instanceof UnaryOperatorToken){
				$operator = $this->unary_operator_registry->get($token->getOperator());
				return new FunctionCallExpressionToken("UO<{$operator->getSymbol()}>", 1, $operator->getOperator(), true);
			}
			throw new RuntimeException("Don't know how to convert {$token->getType()->getName()} token to " . ExpressionToken::class);
		}, $tokens));
	}

	/**
	 * Transforms a given token tree in-place by removing parenthesis tokens
	 * {@see LeftParenthesisToken, RightParenthesisToken} by introducing nesting.
	 *
	 * This transforms [LP, NUM, OP, NUM, RP, OP, NUM] to [[NUM, OP, NUM], OP, NUM].
	 *
	 * @param string $expression
	 * @param Token[] $tokens
	 * @throws ParseException
	 */
	private function deparenthesizeTokens(string $expression, array &$tokens) : void{
		$right_parens = [];
		$right_parens_found = 0;
		for($i = count($tokens) - 1; $i >= 0; --$i){
			$token = $tokens[$i];
			if(!($token instanceof LeftParenthesisToken)){
				if($token instanceof RightParenthesisToken){
					$right_parens[] = $token;
				}
				continue;
			}

			/** @var Token[] $group */
			$group = [];
			$j = $i + 1;
			while(!(
				($tokens[$j] ?? throw new ParseException("No closing parenthesis specified for opening parenthesis at \"" . substr($expression, $token->getStartPos(), $token->getEndPos() - $token->getStartPos()) . "\" ({$token->getStartPos()}:{$token->getEndPos()}) in \"{$expression}\""))
				instanceof RightParenthesisToken
			)){
				$group[] = $tokens[$j++];
			}

			++$right_parens_found;
			array_splice($tokens, $i, 1 + ($j - $i), match(count($group)){
				0 => [],
				1 => $group,
				default => [$group]
			});
		}

		if(isset($right_parens[$right_parens_found])){
			$token = $right_parens[$right_parens_found];
			throw new ParseException("No opening parenthesis specified for closing parenthesis at \"" . substr($expression, $token->getStartPos(), $token->getEndPos() - $token->getStartPos()) . "\" ({$token->getStartPos()}:{$token->getEndPos()}) in \"{$expression}\"");
		}
	}

	/**
	 * Transforms a given token tree in-place by grouping {@see UnaryOperatorToken}
	 * instances together with its operand.
	 *
	 * @param string $expression
	 * @param Token[]|Token[][] $tokens
	 * @throws ParseException
	 */
	private function groupUnaryOperatorTokens(string $expression, array &$tokens) : void{
		$stack = [&$tokens];
		while(($index = array_key_last($stack)) !== null){
			$entry = &$stack[$index];
			unset($stack[$index]);
			for($i = count($entry) - 1; $i >= 0; --$i){
				$token = $entry[$i];
				if(!($token instanceof UnaryOperatorToken)){
					if(is_array($token)){
						$stack[] = &$entry[$i];
					}
					continue;
				}

				array_splice($entry, $i, 2, [[
					$token,
					$entry[$i + 1] ?? throw new ParseException("No right operand specified for unary operator at \"" . substr($expression, $token->getStartPos(), $token->getEndPos() - $token->getStartPos()) . "\" ({$token->getStartPos()}:{$token->getEndPos()}) in \"{$expression}\"")
				]]);
			}
		}
	}

	/**
	 * Transforms a given token tree in-place by grouping all binary operations for
	 * low-complexity processing, converting [TOK, BOP, TOK, BOP, TOK] to
	 * [[[TOK, BOP, TOK], BOP, TOK]].
	 *
	 * @param string $expression
	 * @param Token[]|Token[][] $tokens
	 * @throws ParseException
	 */
	private function groupBinaryOperations(string $expression, array &$tokens) : void{
		foreach($tokens as $i => $value){
			if(is_array($value)){
				$this->groupBinaryOperations($expression, $tokens[$i]);
			}
		}
		foreach($this->binary_operator_registry->getRegisteredByPrecedence() as $list){
			$operators = $list->getOperators();
			foreach($list->getAssignment()->traverse($operators, $tokens) as $index => $value){
				array_splice($tokens, $index - 1, 3, [[
					$tokens[$index - 1] ?? throw new ParseException("No left operand specified for binary operator at \"" . substr($expression, $value->getStartPos(), $value->getEndPos() - $value->getStartPos()) . "\" ({$value->getStartPos()}:{$value->getEndPos()}) in \"{$expression}\""),
					$value,
					$tokens[$index + 1] ?? throw new ParseException("No right operand specified for binary operator at \"" . substr($expression, $value->getStartPos(), $value->getEndPos() - $value->getStartPos()) . "\" ({$value->getStartPos()}:{$value->getEndPos()}) in \"{$expression}\"")
				]]);
			}
		}
	}

	/**
	 * Transforms a given token tree in-place by grouping all function calls with
	 * their argument list.
	 *
	 * @param Token[]|Token[][] $token_tree
	 */
	private function groupFunctionCallTokens(array &$token_tree) : void{
		for($i = count($token_tree) - 1; $i >= 0; --$i){
			$token = $token_tree[$i];
			if(is_array($token)){
				$this->groupFunctionCallTokens($token_tree[$i]);
				continue;
			}
			if($token instanceof FunctionCallToken){
				if(isset($token_tree[$i + 1]) && $token->getArgumentCount() > 0){
					array_splice($token_tree, $i, 2, [[$token, is_array($token_tree[$i + 1]) ? $token_tree[$i + 1] : [$token_tree[$i + 1]]]]);
				}else{
					$token_tree[$i] = [$token];
				}
			}
		}
	}

	/**
	 * Transforms a given token tree in-place by resolving optional function parameters
	 * by their default values.
	 *
	 * @param string $expression
	 * @param Token[]|Token[][] $token_tree
	 * @throws ParseException
	 */
	private function transformFunctionCallTokens(string $expression, array &$token_tree) : void{
		for($i = count($token_tree) - 1; $i >= 0; --$i){
			$token = $token_tree[$i];
			if(is_array($token)){
				$this->transformFunctionCallTokens($expression, $token_tree[$i]);
				continue;
			}

			if(!($token instanceof FunctionCallToken)){
				continue;
			}

			try{
				$function = $this->function_registry->get($token->getFunction());
			}catch(InvalidArgumentException $e){
				throw new ParseException("Cannot resolve function call at \"" . substr($expression, $token->getStartPos(), $token->getEndPos() - $token->getStartPos()) . "\" ({$token->getStartPos()}:{$token->getEndPos()}) in \"{$expression}\": {$e->getMessage()}");
			}

			$args_c = $token->getArgumentCount();
			$param_tokens = $token_tree[$i + 1] ?? [];
			assert(is_array($param_tokens));

			if(isset($param_tokens[0]) && $param_tokens[0] instanceof FunctionCallArgumentSeparatorToken){
				array_unshift($param_tokens, null);
			}

			$last = array_key_last($param_tokens);
			if($last !== null && $param_tokens[$last] instanceof FunctionCallArgumentSeparatorToken){
				$param_tokens[] = null;
			}

			for($j = count($param_tokens) - 1; $j >= 1; --$j){
				if(
					$param_tokens[$j] instanceof FunctionCallArgumentSeparatorToken &&
					$param_tokens[$j - 1] instanceof FunctionCallArgumentSeparatorToken
				){
					array_splice($param_tokens, $j - 1, 2, [$param_tokens[$j - 1], null, $param_tokens[$j]]);
				}
			}

			$params = [];
			for($j = 0, $max = count($param_tokens); $j < $max; ++$j){
				$param_token = $param_tokens[$j];
				if($j % 2 === 0 ? $param_token instanceof FunctionCallArgumentSeparatorToken : !($param_token instanceof FunctionCallArgumentSeparatorToken)){
					assert($param_token !== null);
					throw new ParseException("Unexpected {$param_token->getType()->getName()} token encountered at \"" . substr($expression, $param_token->getStartPos(), $param_token->getEndPos() - $param_token->getStartPos()) . "\" ({$param_token->getStartPos()}:{$param_token->getEndPos()}) in \"{$expression}\"");
				}
				if($j % 2 === 0){
					$params[] = $param_token;
				}
			}

			for($j = count($params), $max = count($function->fallback_param_values) - ($function->variadic ? 1 : 0); $j < $max; ++$j){
				$params[] = null;
			}

			$l = 0;
			for($j = 0, $max = count($params); $j < $max; ++$j){
				if($params[$j] === null){
					if(isset($function->fallback_param_values[$j])){
						$params[$j] = new NumericLiteralToken($token->getStartPos() + $l, $token->getEndPos() + $l, $function->fallback_param_values[$j]);
						++$l;
					}else{
						throw new ParseException(
							"Cannot resolve function call at \"" . substr($expression, $token->getStartPos(), $token->getEndPos() - $token->getStartPos()) . "\" ({$token->getStartPos()}:{$token->getEndPos()}) in \"{$expression}\": " .
							"Function \"{$token->getFunction()}\" does not have a default value for parameter #" . ($j + 1)
						);
					}
				}
			}

			if(count($params) !== $args_c){
				throw new RuntimeException("Failed to parse complete list of arguments (" . count($params) . " !== {$args_c}) in function call at \"" . substr($expression, $token->getStartPos(), $token->getEndPos() - $token->getStartPos()) . "\" ({$token->getStartPos()}:{$token->getEndPos()}) in \"{$expression}\"");
			}

			if(!$function->variadic && count($params) > count($function->fallback_param_values)){
				throw new ParseException(
					"Too many parameters supplied to function call at \"" . substr($expression, $token->getStartPos(), $token->getEndPos() - $token->getStartPos()) . "\" ({$token->getStartPos()}:{$token->getEndPos()}) in \"{$expression}\": " .
					"Expected " . count($function->fallback_param_values) . " parameter" . (count($function->fallback_param_values) === 1 ? "" : "s") . ", got " . count($params) . " parameter" . (count($params) === 1 ? "" : "s")
				);
			}

			array_splice($token_tree, $i, $args_c > 0 ? 2 : 1, [[$token, ...$params]]);
		}
	}

	/**
	 * Transforms a given token tree in-place to a flattened postfix representation.
	 *
	 * @param Token[]|Token[][] $postfix_token_tree
	 */
	public function convertTokenTreeToPostfixTokenTree(array &$postfix_token_tree) : void{
		$stack = [&$postfix_token_tree];
		while(($index = array_key_last($stack)) !== null){
			$entry = &$stack[$index];
			unset($stack[$index]);

			if($entry[0] instanceof FunctionCallToken){
				$entry[] = array_shift($entry);
			}

			$count = count($entry);
			if($count === 2 && $entry[0] instanceof UnaryOperatorToken){
				$entry = [&$entry[1], &$entry[0]];
			}
			if($count === 3 && $entry[1] instanceof BinaryOperatorToken){
				$entry = [&$entry[0], &$entry[2], $entry[1]];
			}

			for($i = 0; $i < $count; ++$i){
				if(is_array($entry[$i])){
					$stack[] = &$entry[$i];
				}
			}
		}

		// flatten tree
		$stack = [&$postfix_token_tree];
		while(($index = array_key_last($stack)) !== null){
			$entry = &$stack[$index];
			unset($stack[$index]);

			$count = count($entry);
			for($i = 0; $i < $count; ++$i){
				if(is_array($entry[$i])){
					array_splice($entry, $i, 1, $entry[$i]);
					$stack[] = &$entry;
					break;
				}
			}
		}
	}
}