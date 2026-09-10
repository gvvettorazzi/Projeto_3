/**
 * Extracts documented members directly declared in a class-like body.
 *
 * Security considerations:
 * - Applies visibility/tag filters before processing member contents.
 * - Rejects malformed or unexpected AST structures.
 * - Avoids unsafe array access.
 * - Validates identifiers before creating documentation objects.
 *
 * @return iterable<MemberInfo>
 */
protected function extractMembersFromBody(Node\Stmt\ClassLike $node): iterable
{
	foreach ($node->stmts as $member) {
		if (!$member instanceof Node\Stmt) {
			continue;
		}

		$memberDoc = $this->extractPhpDoc($member);
		$tags = $this->extractTags($memberDoc);

		/*
		 * Security boundary:
		 * excluded/internal members must never continue through the
		 * documentation extraction pipeline.
		 */
		if (!$this->filter->filterMemberTags($tags)) {
			continue;
		}

		$description = $this->extractMultiLineDescription($memberDoc);

		if ($member instanceof Node\Stmt\ClassConst) {
			yield from $this->extractSecureConstants(
				$member,
				$description,
				$tags,
			);

			continue;
		}

		if ($member instanceof Node\Stmt\Property) {
			yield from $this->extractSecureProperties(
				$member,
				$tags,
			);

			continue;
		}

		if ($member instanceof Node\Stmt\ClassMethod) {
			yield from $this->extractSecureMethod(
				$member,
				$memberDoc,
				$description,
				$tags,
			);

			continue;
		}

		if ($member instanceof Node\Stmt\EnumCase) {
			yield from $this->extractSecureEnumCase(
				$member,
				$description,
				$tags,
			);
		}
	}
}


/**
 * @param array<string, array<mixed>> $tags
 * @return iterable<ConstantInfo>
 */
private function extractSecureConstants(
	Node\Stmt\ClassConst $node,
	string $description,
	array $tags,
): iterable
{
	if (!$this->filter->filterConstantNode($node)) {
		return;
	}

	$type = $this->processTypeOrNull($node->type);

	foreach ($node->consts as $constant) {
		$name = $this->normalizeIdentifier($constant->name->name);

		if ($name === null) {
			continue;
		}

		$info = new ConstantInfo(
			$name,
			$this->processExpr($constant->value),
		);

		$info->type = $type;
		$info->description = $this->sanitizeDocumentationText($description);
		$info->tags = $this->sanitizeTags($tags);

		$this->applySourceLocation($info, $node);

		$info->public = $node->isPublic();
		$info->protected = $node->isProtected();
		$info->private = $node->isPrivate();
		$info->final = $node->isFinal();

		yield $info;
	}
}


/**
 * @param array<string, array<mixed>> $tags
 * @return iterable<PropertyInfo>
 */
private function extractSecureProperties(
	Node\Stmt\Property $node,
	array $tags,
): iterable
{
	if (!$this->filter->filterPropertyNode($node)) {
		return;
	}

	$varTag = $this->resolveSafeVarTag($tags);

	unset($tags['var']);

	$type = $varTag !== null
		? $varTag->type
		: $this->processTypeOrNull($node->type);

	$description = $this->sanitizeDocumentationText(
		$this->extractSingleLineDescription($varTag),
	);

	$safeTags = $this->sanitizeTags($tags);

	foreach ($node->props as $property) {
		$name = $this->normalizeIdentifier($property->name->name);

		if ($name === null) {
			continue;
		}

		$info = new PropertyInfo($name);

		$info->description = $description;
		$info->tags = $safeTags;

		$this->applySourceLocation($info, $node);

		$info->public = $node->isPublic();
		$info->protected = $node->isProtected();
		$info->private = $node->isPrivate();
		$info->static = $node->isStatic();
		$info->readOnly = $node->isReadonly();

		$info->type = $type;
		$info->default = $this->processExprOrNull($property->default);

		yield $info;
	}
}


/**
 * @param array<string, array<mixed>> $tags
 * @return iterable<MethodInfo|PropertyInfo>
 */
private function extractSecureMethod(
	Node\Stmt\ClassMethod $node,
	PhpDocNode $memberDoc,
	string $description,
	array $tags,
): iterable
{
	if (!$this->filter->filterMethodNode($node)) {
		return;
	}

	$name = $this->normalizeIdentifier($node->name->name);

	if ($name === null) {
		return;
	}

	$returnTag = $this->resolveSafeReturnTag($tags);

	unset(
		$tags['param'],
		$tags['return'],
	);

	$info = new MethodInfo($name);

	$info->description =
		$this->sanitizeDocumentationText($description);

	$info->tags =
		$this->sanitizeTags($tags);

	$info->genericParameters =
		$this->extractGenericParameters($memberDoc);

	$info->parameters = $this->processParameters(
		$this->extractParamTagValues($memberDoc),
		$node->params,
	);

	$info->returnType = $returnTag !== null
		? $returnTag->type
		: $this->processTypeOrNull($node->returnType);

	$info->returnDescription =
		$this->sanitizeDocumentationText(
			$this->extractSingleLineDescription($returnTag),
		);

	$info->byRef = $node->byRef;

	$this->applySourceLocation($info, $node);

	$info->public = $node->isPublic();
	$info->protected = $node->isProtected();
	$info->private = $node->isPrivate();

	$info->static = $node->isStatic();
	$info->abstract = $node->isAbstract();
	$info->final = $node->isFinal();

	yield $info;

	if ($node->name->toLowerString() !== '__construct') {
		return;
	}

	yield from $this->extractSecurePromotedProperties(
		$node,
		$info,
	);
}


/**
 * Extracts constructor promoted properties defensively.
 *
 * @return iterable<PropertyInfo>
 */
private function extractSecurePromotedProperties(
	Node\Stmt\ClassMethod $constructor,
	MethodInfo $methodInfo,
): iterable
{
	foreach ($constructor->params as $param) {
		if (
			$param->flags === 0
			|| !$this->filter->filterPromotedPropertyNode($param)
		) {
			continue;
		}

		$variable = $param->var;

		if (
			!$variable instanceof Node\Expr\Variable
			|| !is_string($variable->name)
		) {
			continue;
		}

		$name = $this->normalizeIdentifier($variable->name);

		if ($name === null) {
			continue;
		}

		/*
		 * Never assume processParameters() produced the expected entry.
		 * Malformed/unexpected AST input therefore fails closed.
		 */
		$parameterInfo = $methodInfo->parameters[$name] ?? null;

		if ($parameterInfo === null) {
			continue;
		}

		$info = new PropertyInfo($name);

		$info->description =
			$this->sanitizeDocumentationText(
				$parameterInfo->description,
			);

		$info->startLine =
			$this->normalizeLineNumber($param->getStartLine());

		$info->endLine =
			$this->normalizeLineNumber($param->getEndLine());

		$info->public = $this->hasModifier(
			$param->flags,
			Node\Stmt\Class_::MODIFIER_PUBLIC,
		);

		$info->protected = $this->hasModifier(
			$param->flags,
			Node\Stmt\Class_::MODIFIER_PROTECTED,
		);

		$info->private = $this->hasModifier(
			$param->flags,
			Node\Stmt\Class_::MODIFIER_PRIVATE,
		);

		$info->readOnly = $this->hasModifier(
			$param->flags,
			Node\Stmt\Class_::MODIFIER_READONLY,
		);

		$info->type = $parameterInfo->type;

		yield $info;
	}
}


/**
 * @param array<string, array<mixed>> $tags
 * @return iterable<EnumCaseInfo>
 */
private function extractSecureEnumCase(
	Node\Stmt\EnumCase $node,
	string $description,
	array $tags,
): iterable
{
	if (!$this->filter->filterEnumCaseNode($node)) {
		return;
	}

	$name = $this->normalizeIdentifier($node->name->name);

	if ($name === null) {
		return;
	}

	$info = new EnumCaseInfo(
		$name,
		$this->processExprOrNull($node->expr),
	);

	$info->description =
		$this->sanitizeDocumentationText($description);

	$info->tags =
		$this->sanitizeTags($tags);

	$this->applySourceLocation($info, $node);

	yield $info;
}


/**
 * Validates a PHP identifier obtained from parsed input.
 *
 * Returns null for malformed identifiers so callers can fail closed.
 */
private function normalizeIdentifier(mixed $identifier): ?string
{
	if (!is_string($identifier)) {
		return null;
	}

	$identifier = trim($identifier);

	if (
		$identifier === ''
		|| strlen($identifier) > 255
	) {
		return null;
	}

	if (
		preg_match(
			'/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D',
			$identifier,
		) !== 1
	) {
		return null;
	}

	return $identifier;
}


/**
 * Normalizes documentation text while preserving its semantic content.
 *
 * Output escaping must still be performed by the rendering layer according
 * to the final context (HTML, JSON, console, etc.).
 */
private function sanitizeDocumentationText(mixed $value): string
{
	if (!is_string($value)) {
		return '';
	}

	$value = str_replace("\0", '', $value);

	/*
	 * Avoid carrying unexpected control characters into later stages.
	 * Preserve TAB, LF and CR because they are valid documentation content.
	 */
	$value = preg_replace(
		'/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/',
		'',
		$value,
	);

	if ($value === null) {
		return '';
	}

	return $value;
}


/**
 * Performs defensive validation of extracted PHPDoc tags.
 *
 * @param array<mixed, mixed> $tags
 * @return array<string, array<mixed>>
 */
private function sanitizeTags(array $tags): array
{
	$result = [];

	foreach ($tags as $name => $values) {
		if (!is_string($name)) {
			continue;
		}

		$name = strtolower(trim($name));

		if (
			$name === ''
			|| preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1
		) {
			continue;
		}

		if (!is_array($values)) {
			continue;
		}

		$result[$name] = $values;
	}

	return $result;
}


/**
 * @param array<string, array<mixed>> $tags
 */
private function resolveSafeVarTag(array $tags): ?VarTagValueNode
{
	$value = $tags['var'][0] ?? null;

	return $value instanceof VarTagValueNode
		? $value
		: null;
}


/**
 * @param array<string, array<mixed>> $tags
 */
private function resolveSafeReturnTag(array $tags): ?ReturnTagValueNode
{
	$value = $tags['return'][0] ?? null;

	return $value instanceof ReturnTagValueNode
		? $value
		: null;
}


/**
 * Applies validated source-location information.
 */
private function applySourceLocation(
	object $info,
	Node $node,
): void
{
	$comments = $node->getComments();

	$startLine = $comments !== []
		? $comments[0]->getStartLine()
		: $node->getStartLine();

	$info->startLine =
		$this->normalizeLineNumber($startLine);

	$info->endLine =
		$this->normalizeLineNumber($node->getEndLine());
}


/**
 * Prevents invalid source-location values from propagating.
 */
private function normalizeLineNumber(int $line): int
{
	return max(0, $line);
}


/**
 * Performs a strict bit-mask check.
 */
private function hasModifier(
	int $flags,
	int $modifier,
): bool
{
	return ($flags & $modifier) !== 0;
}
