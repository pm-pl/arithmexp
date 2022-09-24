<?php

declare(strict_types=1);

namespace muqsit\arithmexp\expression;

use Generator;
use InvalidArgumentException;
use muqsit\arithmexp\expression\token\ExpressionToken;
use muqsit\arithmexp\expression\token\NumericLiteralExpressionToken;
use muqsit\arithmexp\expression\token\OperatorExpressionToken;
use muqsit\arithmexp\expression\token\VariableExpressionToken;
use muqsit\arithmexp\ParseException;
use RuntimeException;
use function array_map;
use function array_pop;
use function current;
use function implode;

final class Expression{

	/**
	 * @param BinaryOperatorRegistry $binary_operator_registry
	 * @param ConstantRegistry $constant_registry
	 * @param string $expression
	 * @param ExpressionToken[] $postfix_expression_tokens
	 */
	public function __construct(
		private BinaryOperatorRegistry $binary_operator_registry,
		private ConstantRegistry $constant_registry,
		private string $expression,
		private array $postfix_expression_tokens
	){}

	public function getBinaryOperatorRegistry() : BinaryOperatorRegistry{
		return $this->binary_operator_registry;
	}

	public function getConstantRegistry() : ConstantRegistry{
		return $this->constant_registry;
	}

	public function getExpression() : string{
		return $this->expression;
	}

	/**
	 * @return ExpressionToken[]
	 */
	public function getPostfixExpressionTokens() : array{
		return $this->postfix_expression_tokens;
	}

	/**
	 * @return Generator<string>
	 */
	public function getVariables() : Generator{
		foreach($this->postfix_expression_tokens as $token){
			if($token instanceof VariableExpressionToken){
				yield $token->label;
			}
		}
	}

	/**
	 * @param ExpressionToken $token
	 * @param array<string, int|float> $variable_values
	 * @return int|float
	 */
	private function getValueOf(ExpressionToken $token, array $variable_values) : int|float{
		if($token instanceof NumericLiteralExpressionToken){
			return $token->value;
		}
		if($token instanceof VariableExpressionToken){
			return $this->constant_registry->registered[$token->label] ??
				$variable_values[$token->label] ??
				throw new InvalidArgumentException("No value supplied for variable \"{$token->label}\" in \"{$this->expression}\"");
		}
		throw new RuntimeException("Don't know how to get value of " . $token::class);
	}

	/**
	 * @param array<string, int|float> $variable_values
	 * @return int|float
	 */
	public function evaluate(array $variable_values = []) : int|float{
		$stack = [];
		foreach($this->postfix_expression_tokens as $token){
			if($token instanceof OperatorExpressionToken){
				$right = array_pop($stack) ?? throw new ParseException("No right operand supplied in expression \"{$this->expression}\"");
				$left = array_pop($stack);
				$stack[] = $this->binary_operator_registry->evaluate($token->operator, $left, $right);
			}else{
				$stack[] = $this->getValueOf($token, $variable_values);
			}
		}

		$result = current($stack);
		if($result === false){
			throw new RuntimeException("Could not evaluate \"{$this->expression}\"");
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __debugInfo() : array{
		return [
			"expression" => $this->expression,
			"postfix" => implode("", array_map("strval", $this->postfix_expression_tokens))
		];
	}
}