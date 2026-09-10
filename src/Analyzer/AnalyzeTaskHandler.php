/**
 * Converts a PHP-Parser expression into ApiGen's internal representation.
 *
 * Integrity/security principles:
 * - validates AST structures explicitly;
 * - does not rely on assert(), which may be disabled;
 * - rejects malformed or unsupported nodes;
 * - preserves the original expression semantics;
 * - fails closed when an unexpected structure is encountered.
 */
protected function processExpr(Node\Expr $expr): ExprInfo
{
	return match (true) {
		$expr instanceof Node\Scalar\LNumber =>
			$this->processIntegerExpr($expr),

		$expr instanceof Node\Scalar\DNumber =>
			$this->processFloatExpr($expr),

		$expr instanceof Node\Scalar\String_ =>
			$this->processStringExpr($expr),

		$expr instanceof Node\Expr\Array_ =>
			$this->processArrayExpr($expr),

		$expr instanceof Node\Expr\ClassConstFetch =>
			$this->processClassConstFetchExpr($expr),

		$expr instanceof Node\Expr\ConstFetch =>
			$this->processConstFetchExpr($expr),

		$expr instanceof Node\Scalar\MagicConst =>
			$this->processMagicConstExpr($expr),

		$expr instanceof Node\Expr\UnaryMinus =>
			$this->processUnaryExpr('-', $expr->expr),

		$expr instanceof Node\Expr\UnaryPlus =>
			$this->processUnaryExpr('+', $expr->expr),

		$expr instanceof Node\Expr\BinaryOp =>
			$this->processBinaryExpr($expr),

		$expr instanceof Node\Expr\Ternary =>
			$this->processTernaryExpr($expr),

		$expr instanceof Node\Expr\ArrayDimFetch =>
			$this->processArrayDimFetchExpr($expr),

		$expr instanceof Node\Expr\PropertyFetch =>
			$this->processPropertyFetchExpr($expr),

		$expr instanceof Node\Expr\NullsafePropertyFetch =>
			$this->processNullSafePropertyFetchExpr($expr),

		$expr instanceof Node\Expr\New_ =>
			$this->processNewExpr($expr),

		default =>
			throw $this->unsupportedExpression($expr),
	};
}


/**
 * Processes an integer literal while preserving its original metadata.
 */
private function processIntegerExpr(
	Node\Scalar\LNumber $expr,
): IntegerExprInfo
{
	return new IntegerExprInfo(
		$expr->value,
		$expr->getAttribute('kind'),
		$expr->getAttribute('rawValue'),
	);
}


/**
 * Processes a floating-point literal.
 */
private function processFloatExpr(
	Node\Scalar\DNumber $expr,
): FloatExprInfo
{
	return new FloatExprInfo(
		$expr->value,
		$expr->getAttribute('rawValue'),
	);
}


/**
 * Processes a string literal.
 */
private function processStringExpr(
	Node\Scalar\String_ $expr,
): StringExprInfo
{
	return new StringExprInfo(
		$expr->value,
		$expr->getAttribute('rawValue'),
	);
}


/**
 * Processes an array expression recursively.
 *
 * Malformed array items are rejected instead of being silently accepted.
 */
private function processArrayExpr(
	Node\Expr\Array_ $expr,
): ArrayExprInfo
{
	$items = [];

	foreach ($expr->items as $item) {
		if ($item === null) {
			throw new \LogicException(
				'Malformed array expression.',
			);
		}

		$key = $this->processExprOrNull(
			$item->key,
		);

		$value = $this->processExpr(
			$item->value,
		);

		$items[] = new ArrayItemExprInfo(
			$key,
			$value,
		);
	}

	return new ArrayExprInfo($items);
}


/**
 * Processes a class constant reference.
 *
 * Explicit validation replaces assert() so validation cannot disappear
 * when assertions are disabled.
 */
private function processClassConstFetchExpr(
	Node\Expr\ClassConstFetch $expr,
): ClassConstantFetchExprInfo
{
	if (!$expr->class instanceof Node\Name) {
		throw new \LogicException(
			'Invalid class constant reference.',
		);
	}

	if (!$expr->name instanceof Node\Identifier) {
		throw new \LogicException(
			'Invalid class constant identifier.',
		);
	}

	$name = $expr->name->toString();

	$this->validateIdentifier(
		$name,
		'class constant',
	);

	return new ClassConstantFetchExprInfo(
		$this->processName($expr->class),
		$name,
	);
}


/**
 * Processes constants and PHP boolean/null literals.
 */
private function processConstFetchExpr(
	Node\Expr\ConstFetch $expr,
): ExprInfo
{
	$name = $expr->name->toString();

	if ($name === '') {
		throw new \LogicException(
			'Invalid constant reference.',
		);
	}

	return match ($expr->name->toLowerString()) {
		'true' => new BooleanExprInfo(true),
		'false' => new BooleanExprInfo(false),
		'null' => new NullExprInfo(),

		default => new ConstantFetchExprInfo(
			$name,
		),
	};
}


/**
 * Processes PHP magic constants.
 */
private function processMagicConstExpr(
	Node\Scalar\MagicConst $expr,
): ConstantFetchExprInfo
{
	$name = $expr->getName();

	if ($name === '') {
		throw new \LogicException(
			'Invalid magic constant.',
		);
	}

	return new ConstantFetchExprInfo($name);
}


/**
 * Processes unary operations.
 */
private function processUnaryExpr(
	string $operator,
	Node\Expr $operand,
): UnaryOpExprInfo
{
	if (
		$operator !== '-'
		&& $operator !== '+'
	) {
		throw new \LogicException(
			'Unsupported unary operator.',
		);
	}

	return new UnaryOpExprInfo(
		$operator,
		$this->processExpr($operand),
	);
}


/**
 * Processes binary expressions while preserving operand order.
 */
private function processBinaryExpr(
	Node\Expr\BinaryOp $expr,
): BinaryOpExprInfo
{
	$operator = $expr->getOperatorSigil();

	if ($operator === '') {
		throw new \LogicException(
			'Invalid binary operator.',
		);
	}

	return new BinaryOpExprInfo(
		$operator,
		$this->processExpr($expr->left),
		$this->processExpr($expr->right),
	);
}


/**
 * Processes ternary expressions recursively.
 */
private function processTernaryExpr(
	Node\Expr\Ternary $expr,
): TernaryExprInfo
{
	return new TernaryExprInfo(
		$this->processExpr($expr->cond),
		$this->processExprOrNull($expr->if),
		$this->processExpr($expr->else),
	);
}


/**
 * Processes an array dimension fetch.
 */
private function processArrayDimFetchExpr(
	Node\Expr\ArrayDimFetch $expr,
): DimFetchExprInfo
{
	if ($expr->dim === null) {
		throw new \LogicException(
			'Array dimension is missing.',
		);
	}

	return new DimFetchExprInfo(
		$this->processExpr($expr->var),
		$this->processExpr($expr->dim),
	);
}


/**
 * Processes a normal property fetch.
 */
private function processPropertyFetchExpr(
	Node\Expr\PropertyFetch $expr,
): PropertyFetchExprInfo
{
	return new PropertyFetchExprInfo(
		$this->processExpr($expr->var),
		$this->processPropertyName($expr->name),
	);
}


/**
 * Processes a null-safe property fetch.
 */
private function processNullSafePropertyFetchExpr(
	Node\Expr\NullsafePropertyFetch $expr,
): NullSafePropertyFetchExprInfo
{
	return new NullSafePropertyFetchExprInfo(
		$this->processExpr($expr->var),
		$this->processPropertyName($expr->name),
	);
}


/**
 * Validates and converts a property name.
 *
 * @return ExprInfo|string
 */
private function processPropertyName(
	Node\Expr|Node\Identifier $name,
): ExprInfo|string
{
	if ($name instanceof Node\Expr) {
		return $this->processExpr($name);
	}

	$propertyName = $name->name;

	$this->validateIdentifier(
		$propertyName,
		'property',
	);

	return $propertyName;
}


/**
 * Processes object instantiation.
 *
 * Dynamic class names are deliberately rejected because the original
 * ApiGen representation expects a statically resolvable class name.
 */
private function processNewExpr(
	Node\Expr\New_ $expr,
): NewExprInfo
{
	if (!$expr->class instanceof Name) {
		throw new \LogicException(
			'Dynamic class names are not supported in constant expressions.',
		);
	}

	$args = [];

	foreach ($expr->args as $arg) {
		if (!$arg instanceof Node\Arg) {
			throw new \LogicException(
				'Invalid constructor argument.',
			);
		}

		$argumentName = $arg->name?->name;

		if ($argumentName !== null) {
			$this->validateIdentifier(
				$argumentName,
				'argument',
			);
		}

		$args[] = new ArgExprInfo(
			$argumentName,
			$this->processExpr($arg->value),
		);
	}

	return new NewExprInfo(
		$this->processName($expr->class),
		$args,
	);
}


/**
 * Performs defensive validation of identifiers originating from the AST.
 *
 * PHP-Parser already performs syntax validation, but this check establishes
 * a second integrity boundary before data enters ApiGen's internal model.
 */
private function validateIdentifier(
	string $identifier,
	string $context,
): void
{
	if (
		$identifier === ''
		|| strlen($identifier) > 255
		|| str_contains($identifier, "\0")
	) {
		throw new \LogicException(
			sprintf(
				'Invalid %s identifier.',
				$context,
			),
		);
	}

	if (
		preg_match(
			'/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D',
			$identifier,
		) !== 1
	) {
		throw new \LogicException(
			sprintf(
				'Malformed %s identifier.',
				$context,
			),
		);
	}
}


/**
 * Creates a controlled exception for unsupported AST expressions.
 *
 * Avoids serializing or dumping the complete expression object, which
 * could unnecessarily expose source-code contents.
 */
private function unsupportedExpression(
	Node\Expr $expr,
): \LogicException
{
	return new \LogicException(
		sprintf(
			'Unsupported expression type: %s.',
			get_debug_type($expr),
		),
	);
}

