<?php

declare(strict_types=1);

namespace muqsit\arithmexp;

use muqsit\arithmexp\operator\binary\BinaryOperatorRegistry;
use muqsit\arithmexp\operator\unary\UnaryOperatorRegistry;
use muqsit\arithmexp\token\builder\BinaryOperatorTokenBuilder;
use muqsit\arithmexp\token\builder\FunctionCallTokenBuilder;
use muqsit\arithmexp\token\builder\IdentifierTokenBuilder;
use muqsit\arithmexp\token\builder\NumericLiteralTokenBuilder;
use muqsit\arithmexp\token\builder\ParenthesisTokenBuilder;
use muqsit\arithmexp\token\builder\TokenBuilder;
use muqsit\arithmexp\token\builder\TokenBuilderState;
use muqsit\arithmexp\token\builder\UnaryOperatorTokenBuilder;
use muqsit\arithmexp\token\Token;
use RuntimeException;

final class Scanner{

	public static function createDefault(BinaryOperatorRegistry $binary_operator_registry, UnaryOperatorRegistry $unary_operator_registry) : self{
		return new self([
			new ParenthesisTokenBuilder(),
			new NumericLiteralTokenBuilder(),
			new FunctionCallTokenBuilder(),
			new IdentifierTokenBuilder(),
			UnaryOperatorTokenBuilder::createDefault($unary_operator_registry),
			BinaryOperatorTokenBuilder::createDefault($binary_operator_registry)
		]);
	}

	/**
	 * @param TokenBuilder[] $token_scanners
	 */
	public function __construct(
		private array $token_scanners
	){}

	/**
	 * Scans a given expression and interprets it as a series of tokens.
	 *
	 * @param string $expression
	 * @return Token[]
	 * @throws ParseException
	 */
	public function scan(string $expression) : array{
		reset($this->token_scanners);
		$state = TokenBuilderState::fromExpression($expression);
		while($state->offset < $state->length){
			if($state->expression[$state->offset] === " "){ // ignore space
				$state->offset++;
				continue;
			}

			$scanner = current($this->token_scanners);
			if($scanner === false){
				$scanner = reset($this->token_scanners);
				if($scanner === false){
					throw new RuntimeException("No token scanner could be found");
				}
			}

			$last_token_end = null;
			foreach($scanner->build($state) as $token){
				$last_token_end = $token->getPos()->getEnd();
				$state->captured_tokens[] = $token;
			}

			next($this->token_scanners);
			if($last_token_end === null){
				if(++$state->unknown_token_seq === count($this->token_scanners)){
					throw ParseException::unexpectedTokenWhenParsing($state);
				}
				continue;
			}

			$state->offset = $last_token_end;
			$state->unknown_token_seq = 0;
		}

		foreach($this->token_scanners as $scanner){
			$scanner->transform($state);
		}

		return $state->captured_tokens;
	}
}