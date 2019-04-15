<?php
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use PhpParser;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\MethodAnalyzer;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\DeprecatedClass;
use Psalm\Issue\InvalidStringClass;
use Psalm\Issue\InternalClass;
use Psalm\Issue\MixedMethodCall;
use Psalm\Issue\ParentNotFound;
use Psalm\Issue\UndefinedClass;
use Psalm\Issue\UndefinedMethod;
use Psalm\IssueBuffer;
use Psalm\Storage\Assertion;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

/**
 * @internal
 */
class StaticCallAnalyzer extends \Psalm\Internal\Analyzer\Statements\Expression\CallAnalyzer
{
    /**
     * @param   StatementsAnalyzer               $statements_analyzer
     * @param   PhpParser\Node\Expr\StaticCall  $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr\StaticCall $stmt,
        Context $context
    ) {
        $method_id = null;

        $lhs_type = null;

        $file_analyzer = $statements_analyzer->getFileAnalyzer();
        $codebase = $statements_analyzer->getCodebase();
        $source = $statements_analyzer->getSource();

        $stmt->inferredType = null;

        $config = $codebase->config;

        if ($stmt->class instanceof PhpParser\Node\Name) {
            $fq_class_name = null;

            if (count($stmt->class->parts) === 1
                && in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if ($stmt->class->parts[0] === 'parent') {
                    $child_fq_class_name = $context->self;

                    $class_storage = $child_fq_class_name
                        ? $codebase->classlike_storage_provider->get($child_fq_class_name)
                        : null;

                    if (!$class_storage || !$class_storage->parent_classes) {
                        if (IssueBuffer::accepts(
                            new ParentNotFound(
                                'Cannot call method on parent as this class does not extend another',
                                new CodeLocation($statements_analyzer->getSource(), $stmt)
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return;
                    }

                    $fq_class_name = reset($class_storage->parent_classes);

                    $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);

                    $fq_class_name = $class_storage->name;
                } elseif ($context->self) {
                    if ($stmt->class->parts[0] === 'static' && isset($context->vars_in_scope['$this'])) {
                        $fq_class_name = (string) $context->vars_in_scope['$this'];
                        $lhs_type = clone $context->vars_in_scope['$this'];
                    } else {
                        $fq_class_name = $context->self;
                    }
                } else {
                    $namespace = $statements_analyzer->getNamespace()
                        ? $statements_analyzer->getNamespace() . '\\'
                        : '';

                    $fq_class_name = $namespace . $statements_analyzer->getClassName();
                }

                if ($context->isPhantomClass($fq_class_name)) {
                    return null;
                }
            } elseif ($context->check_classes) {
                $aliases = $statements_analyzer->getAliases();

                if ($context->calling_method_id
                    && !$stmt->class instanceof PhpParser\Node\Name\FullyQualified
                ) {
                    $codebase->file_reference_provider->addCallingMethodReferenceToClassMember(
                        $context->calling_method_id,
                        'use:' . $stmt->class->parts[0] . ':' . \md5($statements_analyzer->getFilePath())
                    );
                }

                $fq_class_name = ClassLikeAnalyzer::getFQCLNFromNameObject(
                    $stmt->class,
                    $aliases
                );

                if ($context->isPhantomClass($fq_class_name)) {
                    return null;
                }

                $does_class_exist = false;

                if ($context->self) {
                    $self_storage = $codebase->classlike_storage_provider->get($context->self);

                    if (isset($self_storage->used_traits[strtolower($fq_class_name)])) {
                        $fq_class_name = $context->self;
                        $does_class_exist = true;
                    }
                }

                if (!$does_class_exist) {
                    $does_class_exist = ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
                        $statements_analyzer,
                        $fq_class_name,
                        new CodeLocation($source, $stmt->class),
                        $statements_analyzer->getSuppressedIssues(),
                        false,
                        false,
                        false
                    );
                }

                if (!$does_class_exist) {
                    return $does_class_exist;
                }
            }

            if ($codebase->store_node_types && $fq_class_name) {
                $codebase->analyzer->addNodeReference(
                    $statements_analyzer->getFilePath(),
                    $stmt->class,
                    $fq_class_name
                );
            }

            if ($fq_class_name && !$lhs_type) {
                $lhs_type = new Type\Union([new TNamedObject($fq_class_name)]);
            }
        } else {
            ExpressionAnalyzer::analyze($statements_analyzer, $stmt->class, $context);
            $lhs_type = $stmt->class->inferredType;
        }

        if (!$lhs_type) {
            if (self::checkFunctionArguments(
                $statements_analyzer,
                $stmt->args,
                null,
                null,
                $context
            ) === false) {
                return false;
            }

            return null;
        }

        $has_mock = false;

        foreach ($lhs_type->getTypes() as $lhs_type_part) {
            $intersection_types = [];

            if ($lhs_type_part instanceof TNamedObject) {
                $fq_class_name = $lhs_type_part->value;

                if (!ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
                    $statements_analyzer,
                    $fq_class_name,
                    new CodeLocation($source, $stmt->class),
                    $statements_analyzer->getSuppressedIssues(),
                    false
                )) {
                    return false;
                }

                $intersection_types = $lhs_type_part->extra_types;
            } elseif ($lhs_type_part instanceof Type\Atomic\TClassString
                && $lhs_type_part->as_type
            ) {
                $fq_class_name = $lhs_type_part->as_type->value;

                if (!ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
                    $statements_analyzer,
                    $fq_class_name,
                    new CodeLocation($source, $stmt->class),
                    $statements_analyzer->getSuppressedIssues(),
                    false
                )) {
                    return false;
                }

                $intersection_types = $lhs_type_part->as_type->extra_types;
            } elseif ($lhs_type_part instanceof Type\Atomic\TLiteralClassString) {
                $fq_class_name = $lhs_type_part->value;

                if (!ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
                    $statements_analyzer,
                    $fq_class_name,
                    new CodeLocation($source, $stmt->class),
                    $statements_analyzer->getSuppressedIssues(),
                    false
                )) {
                    return false;
                }
            } elseif ($lhs_type_part instanceof Type\Atomic\TTemplateParam
                && !$lhs_type_part->as->isMixed()
                && !$lhs_type_part->as->hasObject()
            ) {
                $fq_class_name = null;

                foreach ($lhs_type_part->as->getTypes() as $generic_param_type) {
                    if (!$generic_param_type instanceof TNamedObject) {
                        continue 2;
                    }

                    $fq_class_name = $generic_param_type->value;
                    break;
                }

                if (!$fq_class_name) {
                    if (IssueBuffer::accepts(
                        new UndefinedClass(
                            'Type ' . $lhs_type_part->as . ' cannot be called as a class',
                            new CodeLocation($statements_analyzer->getSource(), $stmt),
                            (string) $lhs_type_part
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    continue;
                }
            } else {
                if ($lhs_type_part instanceof Type\Atomic\TMixed
                    || $lhs_type_part instanceof Type\Atomic\TTemplateParam
                    || $lhs_type_part instanceof Type\Atomic\TClassString
                ) {
                    if (IssueBuffer::accepts(
                        new MixedMethodCall(
                            'Cannot call method on an unknown class',
                            new CodeLocation($statements_analyzer->getSource(), $stmt)
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    continue;
                }

                if ($lhs_type_part instanceof Type\Atomic\TString) {
                    if ($config->allow_string_standin_for_class
                        && !$lhs_type_part instanceof Type\Atomic\TNumericString
                    ) {
                        continue;
                    }

                    if (IssueBuffer::accepts(
                        new InvalidStringClass(
                            'String cannot be used as a class',
                            new CodeLocation($statements_analyzer->getSource(), $stmt)
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    continue;
                }

                if ($lhs_type_part instanceof Type\Atomic\TNull
                    && $lhs_type->ignore_nullable_issues
                ) {
                    continue;
                }

                if (IssueBuffer::accepts(
                    new UndefinedClass(
                        'Type ' . $lhs_type_part . ' cannot be called as a class',
                        new CodeLocation($statements_analyzer->getSource(), $stmt),
                        (string) $lhs_type_part
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // fall through
                }

                continue;
            }

            $fq_class_name = $codebase->classlikes->getUnAliasedName($fq_class_name);

            $is_mock = ExpressionAnalyzer::isMock($fq_class_name);

            $has_mock = $has_mock || $is_mock;

            if ($stmt->name instanceof PhpParser\Node\Identifier && !$is_mock) {
                $method_name_lc = strtolower($stmt->name->name);
                $method_id = $fq_class_name . '::' . $method_name_lc;
                $cased_method_id = $fq_class_name . '::' . $stmt->name->name;

                $args = $stmt->args;

                if ($intersection_types
                    && !$codebase->methods->methodExists($method_id)
                ) {
                    foreach ($intersection_types as $intersection_type) {
                        if (!$intersection_type instanceof TNamedObject) {
                            continue;
                        }

                        $intersection_method_id = $intersection_type->value . '::' . $method_name_lc;

                        if ($codebase->methods->methodExists($intersection_method_id)) {
                            $method_id = $intersection_method_id;
                            $fq_class_name = $intersection_type->value;
                            $cased_method_id = $fq_class_name . '::' . $stmt->name->name;
                            break;
                        }
                    }
                }

                if (!$codebase->methods->methodExists(
                    $method_id,
                    $context->calling_method_id,
                    $codebase->collect_references ? new CodeLocation($source, $stmt->name) : null,
                    null,
                    $statements_analyzer->getFilePath()
                )
                    || !MethodAnalyzer::isMethodVisible(
                        $method_id,
                        $context,
                        $statements_analyzer->getSource()
                    )
                ) {
                    if ($codebase->methods->methodExists(
                        $fq_class_name . '::__callStatic',
                        $context->calling_method_id,
                        $codebase->collect_references ? new CodeLocation($source, $stmt->name) : null,
                        null,
                        $statements_analyzer->getFilePath()
                    )) {
                        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);

                        if (isset($class_storage->pseudo_static_methods[$method_name_lc])) {
                            $pseudo_method_storage = $class_storage->pseudo_static_methods[$method_name_lc];

                            if (self::checkFunctionArguments(
                                $statements_analyzer,
                                $args,
                                $pseudo_method_storage->params,
                                $method_id,
                                $context
                            ) === false) {
                                return false;
                            }

                            $generic_params = [];

                            if (self::checkFunctionLikeArgumentsMatch(
                                $statements_analyzer,
                                $args,
                                null,
                                $pseudo_method_storage->params,
                                $pseudo_method_storage,
                                null,
                                $generic_params,
                                new CodeLocation($source, $stmt),
                                $context
                            ) === false) {
                                return false;
                            }

                            if ($pseudo_method_storage->return_type) {
                                $return_type_candidate = clone $pseudo_method_storage->return_type;

                                $return_type_candidate = ExpressionAnalyzer::fleshOutType(
                                    $codebase,
                                    $return_type_candidate,
                                    $fq_class_name,
                                    $fq_class_name
                                );

                                if (!isset($stmt->inferredType)) {
                                    $stmt->inferredType = $return_type_candidate;
                                } else {
                                    $stmt->inferredType = Type::combineUnionTypes(
                                        $return_type_candidate,
                                        $stmt->inferredType
                                    );
                                }

                                return;
                            }
                        } else {
                            if (self::checkFunctionArguments(
                                $statements_analyzer,
                                $args,
                                null,
                                null,
                                $context
                            ) === false) {
                                return false;
                            }
                        }

                        $array_values = array_map(
                            /**
                             * @return PhpParser\Node\Expr\ArrayItem
                             */
                            function (PhpParser\Node\Arg $arg) {
                                return new PhpParser\Node\Expr\ArrayItem($arg->value);
                            },
                            $args
                        );

                        $args = [
                            new PhpParser\Node\Arg(new PhpParser\Node\Scalar\String_($method_id)),
                            new PhpParser\Node\Arg(new PhpParser\Node\Expr\Array_($array_values)),
                        ];

                        $method_id = $fq_class_name . '::__callstatic';
                    }

                    if (!$context->check_methods) {
                        if (self::checkFunctionArguments(
                            $statements_analyzer,
                            $stmt->args,
                            null,
                            null,
                            $context
                        ) === false) {
                            return false;
                        }

                        return null;
                    }
                }

                $does_method_exist = MethodAnalyzer::checkMethodExists(
                    $codebase,
                    $method_id,
                    new CodeLocation($source, $stmt),
                    $statements_analyzer->getSuppressedIssues(),
                    $context->calling_method_id
                );

                if (!$does_method_exist) {
                    if (self::checkFunctionArguments(
                        $statements_analyzer,
                        $stmt->args,
                        null,
                        null,
                        $context
                    ) === false) {
                        return false;
                    }

                    return;
                }

                $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);

                if ($class_storage->user_defined
                    && $context->self
                    && ($context->collect_mutations || $context->collect_initializations)
                ) {
                    $appearing_method_id = $codebase->getAppearingMethodId($method_id);

                    if (!$appearing_method_id) {
                        if (IssueBuffer::accepts(
                            new UndefinedMethod(
                                'Method ' . $method_id . ' does not exist',
                                new CodeLocation($statements_analyzer->getSource(), $stmt),
                                $method_id
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            //
                        }

                        return;
                    }

                    list($appearing_method_class_name) = explode('::', $appearing_method_id);

                    if ($codebase->classExtends($context->self, $appearing_method_class_name)) {
                        $old_context_include_location = $context->include_location;
                        $old_self = $context->self;
                        $context->include_location = new CodeLocation($statements_analyzer->getSource(), $stmt);
                        $context->self = $appearing_method_class_name;

                        if ($context->collect_mutations) {
                            $file_analyzer->getMethodMutations($method_id, $context);
                        } else {
                            // collecting initializations
                            $local_vars_in_scope = [];
                            $local_vars_possibly_in_scope = [];

                            foreach ($context->vars_in_scope as $var => $_) {
                                if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                                    $local_vars_in_scope[$var] = $context->vars_in_scope[$var];
                                }
                            }

                            foreach ($context->vars_possibly_in_scope as $var => $_) {
                                if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                                    $local_vars_possibly_in_scope[$var] = $context->vars_possibly_in_scope[$var];
                                }
                            }

                            if (!isset($context->initialized_methods[$method_id])) {
                                if ($context->initialized_methods === null) {
                                    $context->initialized_methods = [];
                                }

                                $context->initialized_methods[$method_id] = true;

                                $file_analyzer->getMethodMutations($method_id, $context);

                                foreach ($local_vars_in_scope as $var => $type) {
                                    $context->vars_in_scope[$var] = $type;
                                }

                                foreach ($local_vars_possibly_in_scope as $var => $type) {
                                    $context->vars_possibly_in_scope[$var] = $type;
                                }
                            }
                        }

                        $context->include_location = $old_context_include_location;
                        $context->self = $old_self;

                        if (isset($context->vars_in_scope['$this']) && $old_self) {
                            $context->vars_in_scope['$this'] = Type::parseString($old_self);
                        }
                    }
                }

                if ($class_storage->deprecated) {
                    if (IssueBuffer::accepts(
                        new DeprecatedClass(
                            $fq_class_name . ' is marked deprecated',
                            new CodeLocation($statements_analyzer->getSource(), $stmt)
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if ($class_storage->internal
                    && $context->self
                    && !$context->collect_initializations
                    && !$context->collect_mutations
                ) {
                    $self_root = preg_replace('/^([^\\\]+).*/', '$1', $context->self);
                    $declaring_root = preg_replace('/^([^\\\]+).*/', '$1', $fq_class_name);

                    if (strtolower($self_root) !== strtolower($declaring_root)) {
                        if (IssueBuffer::accepts(
                            new InternalClass(
                                $fq_class_name . ' is marked internal',
                                new CodeLocation($statements_analyzer->getSource(), $stmt)
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }

                if (MethodAnalyzer::checkMethodVisibility(
                    $method_id,
                    $context,
                    $statements_analyzer->getSource(),
                    new CodeLocation($source, $stmt),
                    $statements_analyzer->getSuppressedIssues()
                ) === false) {
                    return false;
                }

                if ((!$stmt->class instanceof PhpParser\Node\Name
                        || $stmt->class->parts[0] !== 'parent'
                        || $statements_analyzer->isStatic())
                    && (
                        !$context->self
                        || $statements_analyzer->isStatic()
                        || !$codebase->classExtends($context->self, $fq_class_name)
                    )
                ) {
                    if (MethodAnalyzer::checkStatic(
                        $method_id,
                        ($stmt->class instanceof PhpParser\Node\Name
                            && strtolower($stmt->class->parts[0]) === 'self')
                            || $context->self === $fq_class_name,
                        !$statements_analyzer->isStatic(),
                        $codebase,
                        new CodeLocation($source, $stmt),
                        $statements_analyzer->getSuppressedIssues(),
                        $is_dynamic_this_method
                    ) === false) {
                        // fall through
                    }

                    if ($is_dynamic_this_method) {
                        $fake_method_call_expr = new PhpParser\Node\Expr\MethodCall(
                            new PhpParser\Node\Expr\Variable(
                                'this',
                                $stmt->class->getAttributes()
                            ),
                            $stmt->name,
                            $stmt->args,
                            $stmt->getAttributes()
                        );

                        if (MethodCallAnalyzer::analyze(
                            $statements_analyzer,
                            $fake_method_call_expr,
                            $context
                        ) === false) {
                            return false;
                        }

                        if (isset($fake_method_call_expr->inferredType)) {
                            $stmt->inferredType = $fake_method_call_expr->inferredType;
                        }

                        return null;
                    }
                }

                if (MethodAnalyzer::checkMethodNotDeprecatedOrInternal(
                    $codebase,
                    $context,
                    $method_id,
                    new CodeLocation($statements_analyzer->getSource(), $stmt),
                    $statements_analyzer->getSuppressedIssues()
                ) === false) {
                    // fall through
                }

                $found_generic_params = MethodCallAnalyzer::getClassTemplateParams(
                    $codebase,
                    $class_storage,
                    $fq_class_name,
                    $method_name_lc,
                    $lhs_type_part,
                    null
                );

                if (self::checkMethodArgs(
                    $method_id,
                    $args,
                    $found_generic_params,
                    $context,
                    new CodeLocation($statements_analyzer->getSource(), $stmt),
                    $statements_analyzer
                ) === false) {
                    return false;
                }

                $fq_class_name = $stmt->class instanceof PhpParser\Node\Name && $stmt->class->parts === ['parent']
                    ? (string) $statements_analyzer->getFQCLN()
                    : $fq_class_name;

                $self_fq_class_name = $fq_class_name;

                $return_type_candidate = null;

                if ($codebase->methods->return_type_provider->has($fq_class_name)) {
                    $return_type_candidate = $codebase->methods->return_type_provider->getReturnType(
                        $statements_analyzer,
                        $fq_class_name,
                        $stmt->name->name,
                        $stmt->args,
                        $context,
                        new CodeLocation($statements_analyzer->getSource(), $stmt->name)
                    );
                }

                $declaring_method_id = $codebase->methods->getDeclaringMethodId($method_id);

                if (!$return_type_candidate && $declaring_method_id && $declaring_method_id !== $method_id) {
                    list($declaring_fq_class_name, $declaring_method_name) = explode('::', $declaring_method_id);

                    if ($codebase->methods->return_type_provider->has($declaring_fq_class_name)) {
                        $return_type_candidate = $codebase->methods->return_type_provider->getReturnType(
                            $statements_analyzer,
                            $declaring_fq_class_name,
                            $declaring_method_name,
                            $stmt->args,
                            $context,
                            new CodeLocation($statements_analyzer->getSource(), $stmt->name),
                            null,
                            $fq_class_name,
                            $stmt->name->name
                        );
                    }
                }

                if (!$return_type_candidate) {
                    $return_type_candidate = $codebase->methods->getMethodReturnType(
                        $method_id,
                        $self_fq_class_name,
                        $args
                    );

                    if ($return_type_candidate) {
                        $return_type_candidate = clone $return_type_candidate;

                        if ($found_generic_params) {
                            $return_type_candidate->replaceTemplateTypesWithArgTypes(
                                $found_generic_params
                            );
                        }

                        $return_type_candidate = ExpressionAnalyzer::fleshOutType(
                            $codebase,
                            $return_type_candidate,
                            $self_fq_class_name,
                            $fq_class_name
                        );

                        $return_type_location = $codebase->methods->getMethodReturnTypeLocation(
                            $method_id,
                            $secondary_return_type_location
                        );

                        if ($secondary_return_type_location) {
                            $return_type_location = $secondary_return_type_location;
                        }

                        // only check the type locally if it's defined externally
                        if ($return_type_location && !$config->isInProjectDirs($return_type_location->file_path)) {
                            $return_type_candidate->check(
                                $statements_analyzer,
                                new CodeLocation($source, $stmt),
                                $statements_analyzer->getSuppressedIssues(),
                                $context->phantom_classes
                            );
                        }
                    }
                }

                $method_storage = $codebase->methods->getUserMethodStorage($method_id);

                if ($method_storage) {
                    if ($method_storage->assertions) {
                        self::applyAssertionsToContext(
                            $stmt->name,
                            $method_storage->assertions,
                            $stmt->args,
                            $found_generic_params ?: [],
                            $context,
                            $statements_analyzer
                        );
                    }

                    if ($method_storage->if_true_assertions) {
                        $stmt->ifTrueAssertions = array_map(
                            function (Assertion $assertion) use ($found_generic_params) : Assertion {
                                return $assertion->getUntemplatedCopy($found_generic_params ?: []);
                            },
                            $method_storage->if_true_assertions
                        );
                    }

                    if ($method_storage->if_false_assertions) {
                        $stmt->ifFalseAssertions = array_map(
                            function (Assertion $assertion) use ($found_generic_params) : Assertion {
                                return $assertion->getUntemplatedCopy($found_generic_params ?: []);
                            },
                            $method_storage->if_false_assertions
                        );
                    }
                }

                if ($config->after_method_checks) {
                    $file_manipulations = [];

                    $appearing_method_id = $codebase->methods->getAppearingMethodId($method_id);

                    if ($appearing_method_id && $declaring_method_id) {
                        foreach ($config->after_method_checks as $plugin_fq_class_name) {
                            $plugin_fq_class_name::afterMethodCallAnalysis(
                                $stmt,
                                $method_id,
                                $appearing_method_id,
                                $declaring_method_id,
                                $context,
                                $source,
                                $codebase,
                                $file_manipulations,
                                $return_type_candidate
                            );
                        }
                    }

                    if ($file_manipulations) {
                        /** @psalm-suppress MixedTypeCoercion */
                        FileManipulationBuffer::add($statements_analyzer->getFilePath(), $file_manipulations);
                    }
                }

                if ($return_type_candidate) {
                    if (isset($stmt->inferredType)) {
                        $stmt->inferredType = Type::combineUnionTypes($stmt->inferredType, $return_type_candidate);
                    } else {
                        $stmt->inferredType = $return_type_candidate;
                    }
                }
            } else {
                if (self::checkFunctionArguments(
                    $statements_analyzer,
                    $stmt->args,
                    null,
                    null,
                    $context
                ) === false) {
                    return false;
                }
            }

            if ($codebase->store_node_types && $method_id) {
                /** @psalm-suppress PossiblyInvalidArgument never a string, PHP Parser bug */
                $codebase->analyzer->addNodeReference(
                    $statements_analyzer->getFilePath(),
                    $stmt->name,
                    $method_id . '()'
                );
            }

            if ($codebase->store_node_types
                && (!$context->collect_initializations
                    && !$context->collect_mutations)
                && isset($stmt->inferredType)
            ) {
                /** @psalm-suppress PossiblyInvalidArgument never a string, PHP Parser bug */
                $codebase->analyzer->addNodeType(
                    $statements_analyzer->getFilePath(),
                    $stmt->name,
                    (string) $stmt->inferredType
                );
            }
        }

        if ($method_id === null) {
            return self::checkMethodArgs(
                $method_id,
                $stmt->args,
                $found_generic_params,
                $context,
                new CodeLocation($statements_analyzer->getSource(), $stmt),
                $statements_analyzer
            );
        }

        if (!$config->remember_property_assignments_after_call && !$context->collect_initializations) {
            $context->removeAllObjectVars();
        }
    }
}
