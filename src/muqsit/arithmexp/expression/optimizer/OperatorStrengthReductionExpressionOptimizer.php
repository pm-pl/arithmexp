<?php

declare(strict_types=1);

namespace muqsit\arithmexp\expression\optimizer;

use muqsit\arithmexp\expression\ConstantExpression;
use muqsit\arithmexp\expression\Expression;
use muqsit\arithmexp\expression\RawExpression;
use muqsit\arithmexp\expression\token\ExpressionToken;
use muqsit\arithmexp\expression\token\FunctionCallExpressionToken;
use muqsit\arithmexp\expression\token\NumericLiteralExpressionToken;
use muqsit\arithmexp\expression\token\VariableExpressionToken;
use muqsit\arithmexp\ParseException;
use muqsit\arithmexp\Parser;
use muqsit\arithmexp\token\BinaryOperatorToken;
use muqsit\arithmexp\Util;
use RuntimeException;
use function array_splice;
use function assert;
use function count;
use function gettype;
use function is_array;

final class OperatorStrengthReductionExpressionOptimizer implements ExpressionOptimizer{

	public function __construct(){
	}

	public function run(Parser $parser, Expression $expression) : Expression{
		$postfix_expression_tokens = $expression->getPostfixExpressionTokens();

		$changes = 0;
		for($i = count($postfix_expression_tokens) - 1; $i >= 2; --$i){
			$token = $postfix_expression_tokens[$i];
			if(
				!($token instanceof FunctionCallExpressionToken) ||
				!($token->parent instanceof BinaryOperatorToken)
			){
				continue;
			}

			$tree = Util::expressionTokenArrayToTree($postfix_expression_tokens, 0, $i);
			$tree_c = count($tree);
			if($tree_c < $token->argument_count){
				continue;
			}

			$left = $tree[$tree_c - $token->argument_count];
			if(is_array($left)){
				Util::flattenArray($left);
			}else{
				$left = [$left];
			}

			$right = $tree[($tree_c - $token->argument_count) + 1];
			if(is_array($right)){
				Util::flattenArray($right);
			}else{
				$right = [$right];
			}

			$replacement = $this->process($parser, $expression, $token, $left, $right);
			if($replacement === null){
				continue;
			}

			$length = count($left) + count($right);
			array_splice($postfix_expression_tokens, $i - $length, $length + 1, $replacement);
			++$changes;
			$i = count($postfix_expression_tokens);
		}

		return match(true){
			count($postfix_expression_tokens) === 1 && $postfix_expression_tokens[0] instanceof NumericLiteralExpressionToken => new ConstantExpression($expression->getExpression(), $postfix_expression_tokens[0]->value),
			default => $changes > 0 ? new RawExpression($expression->getExpression(), $postfix_expression_tokens) : $expression
		};
	}

	/**
	 * @param ExpressionToken[] $tokens
	 * @param int $value
	 * @return bool
	 */
	private function valueEquals(array $tokens, int $value) : bool{
		return count($tokens) === 1 && $tokens[0] instanceof NumericLiteralExpressionToken && match(gettype($tokens[0]->value)){
			"integer" => $value === $tokens[0]->value,
			"double" => (float) $value === $tokens[0]->value,
			default => throw new RuntimeException("Unexpected numeric literal type: " . gettype($tokens[0]->value))
		};
	}

	/**
	 * @param ExpressionToken[] $x
	 * @param ExpressionToken[] $y
	 * @return bool
	 */
	private function tokensEqualByReturnValue(array $x, array $y) : bool{
		if(count($x) !== count($y)){
			return false;
		}

		for($i = 0, $max = count($x); $i < $max; ++$i){
			if((
				!($x[$i] instanceof VariableExpressionToken /* variables are deterministic during evaluation */) &&
				!$x[$i]->isDeterministic()
			) || !$x[$i]->equals($y[$i])){
				return false;
			}
		}

		return true;
	}

	/**
	 * @param Parser $parser
	 * @param Expression $expression
	 * @param FunctionCallExpressionToken $operator_token
	 * @param ExpressionToken[] $left
	 * @param ExpressionToken[] $right
	 * @return ExpressionToken[]|null
	 * @throws ParseException
	 */
	private function process(Parser $parser, Expression $expression, FunctionCallExpressionToken $operator_token, array $left, array $right) : ?array{
		$token = $operator_token->parent;
		assert($token instanceof BinaryOperatorToken);

		$m_op = $parser->getBinaryOperatorRegistry()->get("*");

		return match($token->getOperator()){
			"**" => match(true){
				$this->valueEquals($left, 0) => [new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens($left), 0)],
				$this->valueEquals($left, 1) => [new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens($left), 1)],
				$this->valueEquals($right, 2) => [
					...$left,
					...$left,
					new FunctionCallExpressionToken(Util::positionContainingExpressionTokens($right), $m_op->getSymbol(), 2, $m_op->getOperator(), $m_op->isDeterministic(), $m_op->isCommutative(), $token)
				],
				$this->valueEquals($right, 1) => $left,
				$this->valueEquals($right, 0) => [new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens($right), 1)],
				default => null
			},
			"*" => match(true){
				$this->valueEquals($left, 1) => $right,
				$this->valueEquals($right, 1) => $left,
				$this->valueEquals($left, 0) => [new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens($left), 0)],
				$this->valueEquals($right, 0) => [new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens($right), 0)],
				default => null
			},
			"/" => match(true){
				$this->valueEquals($left, 0) => [new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens($left), 0)],
				$this->valueEquals($right, 0) => throw ParseException::unresolvableExpressionDivisionByZero($expression->getExpression(), Util::positionContainingExpressionTokens($right)),
				$this->valueEquals($right, 1) => $left,
				default => $this->processDivision($parser, $operator_token, $left, $right)
			},
			"+", => match(true){
				$this->valueEquals($left, 0) => $right,
				$this->valueEquals($right, 0) => $left,
				default => null
			},
			"-" => match(true){
				$this->tokensEqualByReturnValue($left, $right) => [new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens([...$left, ...$right]), 0)],
				$this->valueEquals($left, 0) => $right,
				$this->valueEquals($right, 0) => $left,
				default => null
			},
			default => null
		};
	}

	/**
	 * @param Parser $parser
	 * @param FunctionCallExpressionToken $operator_token
	 * @param ExpressionToken[] $left
	 * @param ExpressionToken[] $right
	 * @return ExpressionToken[]|null
	 */
	private function processDivision(Parser $parser, FunctionCallExpressionToken $operator_token, array $left, array $right) : ?array{
		$binary_operator_registry = $parser->getBinaryOperatorRegistry();
		$m_op = $binary_operator_registry->get("*");
		$filter = static fn(array $array) : bool => count($array) === 1 || (
			count($array) === 3 &&
			$array[2] instanceof FunctionCallExpressionToken &&
			$array[2]->parent instanceof BinaryOperatorToken &&
			$binary_operator_registry->get($array[2]->parent->getOperator()) === $m_op
		);

		$left_tree = Util::expressionTokenArrayToTree($left);
		Util::flattenArray($left_tree, $filter);

		$right_tree = Util::expressionTokenArrayToTree($right);
		Util::flattenArray($right_tree, $filter);

		$changed = false;
		foreach($left_tree as $i => $left_operand){
			if(
				$left_operand instanceof FunctionCallExpressionToken ||
				($left_operand instanceof NumericLiteralExpressionToken && $left_operand->value === 1)
			){
				continue;
			}

			$left_operand = is_array($left_operand) ? $left_operand : [$left_operand];
			Util::flattenArray($left_operand);
			foreach($right_tree as $j => $right_operand){
				if(
					$right_operand instanceof FunctionCallExpressionToken ||
					($right_operand instanceof NumericLiteralExpressionToken && $right_operand->value === 1)
				){
					continue;
				}

				$right_operand = is_array($right_operand) ? $right_operand : [$right_operand];
				Util::flattenArray($right_operand);
				if(!$this->tokensEqualByReturnValue($left_operand, $right_operand)){
					continue;
				}

				// on cancelling a value in the numerator with a value in the denominator, replace them operands with 1 (identity element of division)
				$left_tree[$i] = new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens($left_operand), 1);
				$right_tree[$j] = new NumericLiteralExpressionToken(Util::positionContainingExpressionTokens($right_operand), 1);
				$changed = true;
			}
		}

		if(!$changed){
			return null;
		}

		return [...$left_tree, ...$right_tree, $operator_token];
	}
}
