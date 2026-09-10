/**
* Extracts documented members directly declared in a class-like body.
*
* @return iterable<MemberInfo>
*/
protected function extractMembersFromBody(Node\Stmt\ClassLike $node): iterable
{
foreach ($node->stmts as $member) {
$memberDoc = $this->extractPhpDoc($member);
$tags = $this->extractTags($memberDoc);
if (!$this->filter->filterMemberTags($tags)) {
continue;
}
$description = $this->extractMultiLineDescription($memberDoc);
if ($member instanceof Node\Stmt\ClassConst) {
yield from $this->extractConstantsFromNode(
$member,
$description,
$tags,
);
continue;
}
if ($member instanceof Node\Stmt\Property) {
yield from $this->extractPropertiesFromNode(
$member,
$tags,

);
continue;
}
if ($member instanceof Node\Stmt\ClassMethod) {
yield from $this->extractMethodFromNode(
$member,
$memberDoc,
$description,
$tags,
);
continue;
}
if ($member instanceof Node\Stmt\EnumCase) {
yield from $this->extractEnumCaseFromNode(
$member,
$description,
$tags,
);
}
}
}

/**
* @param PhpDocTagValueNode[][] $tags
* @return iterable<ConstantInfo>
*/

private function extractConstantsFromNode(
Node\Stmt\ClassConst $node,
string $description,
array $tags,
): iterable
{
if (!$this->filter->filterConstantNode($node)) {
return;
}
$startLine = $this->resolveMemberStartLine($node);
$endLine = $node->getEndLine();
$type = $this->processTypeOrNull($node->type);
foreach ($node->consts as $constant) {
$info = new ConstantInfo(
$constant->name->name,
$this->processExpr($constant->value),
);
$info->type = $type;
$info->description = $description;
$info->tags = $tags;
$info->startLine = $startLine;
$info->endLine = $endLine;
$info->public = $node->isPublic();
$info->protected = $node->isProtected();
$info->private = $node->isPrivate();
$info->final = $node->isFinal();

yield $info;
}
}

/**
* @param PhpDocTagValueNode[][] $tags
* @return iterable<PropertyInfo>
*/
private function extractPropertiesFromNode(
Node\Stmt\Property $node,
array $tags,
): iterable
{
if (!$this->filter->filterPropertyNode($node)) {
return;
}
$varTag = $this->resolveVarTag($tags);
unset($tags['var']);
$startLine = $this->resolveMemberStartLine($node);
$endLine = $node->getEndLine();
$type = $varTag !== null
? $varTag->type
: $this->processTypeOrNull($node->type);
$description = $this->extractSingleLineDescription($varTag);

foreach ($node->props as $property) {
$info = new PropertyInfo($property->name->name);
$info->description = $description;
$info->tags = $tags;
$info->startLine = $startLine;
$info->endLine = $endLine;
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
* @param PhpDocTagValueNode[][] $tags
* @return iterable<MethodInfo|PropertyInfo>
*/
private function extractMethodFromNode(
Node\Stmt\ClassMethod $node,
PhpDocNode $memberDoc,

string $description,
array $tags,
): iterable
{
if (!$this->filter->filterMethodNode($node)) {
return;
}
$returnTag = $this->resolveReturnTag($tags);
unset(
$tags['param'],
$tags['return'],
);
$info = new MethodInfo($node->name->name);
$info->description = $description;
$info->tags = $tags;
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
$this->extractSingleLineDescription($returnTag);
$info->byRef = $node->byRef;
$info->startLine = $this->resolveMemberStartLine($node);
$info->endLine = $node->getEndLine();
$info->public = $node->isPublic();
$info->protected = $node->isProtected();
$info->private = $node->isPrivate();
$info->static = $node->isStatic();
$info->abstract = $node->isAbstract();
$info->final = $node->isFinal();
yield $info;
if (!$this->isConstructor($node)) {
return;
}
yield from $this->extractPromotedProperties(
$node,
$info,
);
}

/**

* Extracts constructor-promoted properties.
*
* @return iterable<PropertyInfo>
*/
private function extractPromotedProperties(
Node\Stmt\ClassMethod $constructor,
MethodInfo $methodInfo,
): iterable
{
foreach ($constructor->params as $param) {
if (!$this->isAllowedPromotedProperty($param)) {
continue;
}
if (
!$param->var instanceof Node\Expr\Variable
|| !is_string($param->var->name)
) {
continue;
}
$name = $param->var->name;
/*
* Parameter information should normally exist because it was
* previously processed by processParameters(). Guarding the lookup
* prevents an unexpected malformed AST from causing an undefined
* array access.
*/
if (!isset($methodInfo->parameters[$name])) {
continue;

}
$parameterInfo = $methodInfo->parameters[$name];
$info = new PropertyInfo($name);
$info->description = $parameterInfo->description;
$info->startLine = $param->getStartLine();
$info->endLine = $param->getEndLine();
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
* @param PhpDocTagValueNode[][] $tags
* @return iterable<EnumCaseInfo>
*/
private function extractEnumCaseFromNode(
Node\Stmt\EnumCase $node,
string $description,
array $tags,
): iterable
{
if (!$this->filter->filterEnumCaseNode($node)) {
return;
}
$info = new EnumCaseInfo(
$node->name->name,
$this->processExprOrNull($node->expr),
);
$info->description = $description;
$info->tags = $tags;
$info->startLine = $this->resolveMemberStartLine($node);
$info->endLine = $node->getEndLine();

yield $info;
}

/**
* Returns the first source line associated with the member.
*/
private function resolveMemberStartLine(Node $node): int
{
$comments = $node->getComments();
return $comments !== []
? $comments[0]->getStartLine()
: $node->getStartLine();

}

/**
* @param PhpDocTagValueNode[][] $tags
*/
private function resolveVarTag(array $tags): ?VarTagValueNode
{
$value = $tags['var'][0] ?? null;
return $value instanceof VarTagValueNode
? $value
: null;

}

/**
* @param PhpDocTagValueNode[][] $tags
*/
private function resolveReturnTag(array $tags): ?ReturnTagValueNode
{
$value = $tags['return'][0] ?? null;
return $value instanceof ReturnTagValueNode
? $value
: null;

}

/**
* Determines whether the method is the class constructor.
*/
private function isConstructor(Node\Stmt\ClassMethod $method): bool
{
return $method->name->toLowerString() === '__construct';
}

/**
* Determines whether a constructor parameter represents an allowed
* promoted property.
*/
private function isAllowedPromotedProperty(Node\Param $param): bool
{
if ($param->flags === 0) {
return false;
}

return $this->filter->filterPromotedPropertyNode($param);
}

/**
* Checks whether a PHP-Parser modifier flag is present.
*/
private function hasModifier(int $flags, int $modifier): bool
{
return ($flags & $modifier) !== 0;
}
