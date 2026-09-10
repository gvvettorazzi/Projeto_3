protected function processExpr(Node\Expr $expr): ExprInfo
{
	if ($expr instanceof Node\Scalar\LNumber) {
		return new IntegerExprInfo(
			$expr->value,
			$expr->getAttribute('kind'),
			$expr->getAttribute('rawValue'),
		);

	} elseif ($expr instanceof Node\Scalar\DNumber) {
		return new FloatExprInfo(
			$expr->value,
			$expr->getAttribute('rawValue'),
		);

	} elseif ($expr instanceof Node\Scalar\String_) {
		return new StringExprInfo(
			$expr->value,
			$expr->getAttribute('rawValue'),
		);

	} elseif ($expr instanceof Node\Expr\Array_) {
		$items = [];

		foreach ($expr->items as $item) {
			$key = $this->processExprOrNull($item->key);
			$value = $this->processExpr($item->value);

			$items[] = new ArrayItemExprInfo(
				$key,
				$value,
			);
		}

		return new ArrayExprInfo($items);

	} elseif ($expr instanceof Node\Expr\ClassConstFetch) {
		assert($expr->class instanceof Node\Name);
		assert($expr->name instanceof Node\Identifier);

		// TODO: handle 'self' & 'parent' differently?
		return new ClassConstantFetchExprInfo(
			$this->processName($expr->class),
			$expr->name->toString(),
		);

	} elseif ($expr instanceof Node\Expr\ConstFetch) {
		$lower = $expr->name->toLowerString();

		if ($lower === 'true') {
			return new BooleanExprInfo(true);

		} elseif ($lower === 'false') {
			return new BooleanExprInfo(false);

		} elseif ($lower === 'null') {
			return new NullExprInfo();

		} else {
			return new ConstantFetchExprInfo(
				$expr->name->toString(),
			);
		}

	} elseif ($expr instanceof Node\Scalar\MagicConst) {
		return new ConstantFetchExprInfo(
			$expr->getName(),
		);

	} elseif ($expr instanceof Node\Expr\UnaryMinus) {
		return new UnaryOpExprInfo(
			'-',
			$this->processExpr($expr->expr),
		);

	} elseif ($expr instanceof Node\Expr\UnaryPlus) {
		return new UnaryOpExprInfo(
			'+',
			$this->processExpr($expr->expr),
		);

	} elseif ($expr instanceof Node\Expr\BinaryOp) {
		return new BinaryOpExprInfo(
			$expr->getOperatorSigil(),
			$this->processExpr($expr->left),
			$this->processExpr($expr->right),
		);

	} elseif ($expr instanceof Node\Expr\Ternary) {
		return new TernaryExprInfo(
			$this->processExpr($expr->cond),
			$this->processExprOrNull($expr->if),
			$this->processExpr($expr->else),
		);

	} elseif ($expr instanceof Node\Expr\ArrayDimFetch) {
		assert($expr->dim !== null);

		return new DimFetchExprInfo(
			$this->processExpr($expr->var),
			$this->processExpr($expr->dim),
		);

	} elseif ($expr instanceof Node\Expr\PropertyFetch) {
		return new PropertyFetchExprInfo(
			$this->processExpr($expr->var),
			$expr->name instanceof Node\Expr
				? $this->processExpr($expr->name)
				: $expr->name->name,
		);

	} elseif ($expr instanceof Node\Expr\NullsafePropertyFetch) {
		return new NullSafePropertyFetchExprInfo(
			$this->processExpr($expr->var),
			$expr->name instanceof Node\Expr
				? $this->processExpr($expr->name)
				: $expr->name->name,
		);

	} elseif ($expr instanceof Node\Expr\New_) {
		assert($expr->class instanceof Name);

		$args = [];

		foreach ($expr->args as $arg) {
			assert($arg instanceof Node\Arg);

			$args[] = new ArgExprInfo(
				$arg->name?->name,
				$this->processExpr($arg->value),
			);
		}

		return new NewExprInfo(
			$this->processName($expr->class),
			$args,
		);

	} else {
		throw new \LogicException(
			sprintf(
				'Unsupported expr node %s used in constant expression',
				get_debug_type($expr),
			),
		);
	}
}
