<?php

declare(strict_types=1);

namespace Larastan\Larastan\Types;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Larastan\Larastan\Concerns\HasContainer;
use Larastan\Larastan\Internal\LaravelVersion;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;

use function array_map;
use function count;
use function in_array;

class ModelRelationsDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    use HasContainer;

    public function __construct(private RelationParserHelper $relationParserHelper)
    {
    }

    public function getClass(): string
    {
        return Model::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        $variant = $methodReflection->getVariants()[0];

        $returnType = $variant->getReturnType();

        $classNames = $returnType->getObjectClassNames();

        if (count($classNames) !== 1) {
            return false;
        }

        if (! (new ObjectType(Relation::class))->isSuperTypeOf($returnType)->yes()) {
            return false;
        }

        if (! $methodReflection->getDeclaringClass()->hasNativeMethod($methodReflection->getName())) {
            return false;
        }

        if (count($variant->getParameters()) !== 0) {
            return false;
        }

        if (
            in_array($methodReflection->getName(), [
                'hasOne',
                'hasOneThrough',
                'morphOne',
                'belongsTo',
                'morphTo',
                'hasMany',
                'hasManyThrough',
                'morphMany',
                'belongsToMany',
                'morphToMany',
                'morphedByMany',
            ], true)
        ) {
            return false;
        }

        $relatedModel = $this
            ->relationParserHelper
            ->findRelatedModelInRelationMethod($methodReflection);

        return $relatedModel !== null;
    }

    /**
     * @return Type
     *
     * @throws ShouldNotHappenException
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): Type|null {
        $returnType                 = $methodReflection->getVariants()[0]->getReturnType();
        $returnTypeObjectClassNames = $returnType->getObjectClassNames();

        if ($returnTypeObjectClassNames === []) {
            return null;
        }

        /** @var string $relatedModelClassName */
        $relatedModelClassName = $this
            ->relationParserHelper
            ->findRelatedModelInRelationMethod($methodReflection);

        if (!LaravelVersion::hasLaravel1115Generics() && (new ObjectType(BelongsTo::class))->isSuperTypeOf($returnType)->yes()) {
            $classReflection = $methodReflection->getDeclaringClass();
            $types           = [];

            foreach (TypeUtils::flattenTypes($returnType) as $flattenType) {
                if ((new ObjectType(BelongsTo::class))->isSuperTypeOf($flattenType)->yes()) {
                    $types[] = $flattenType->getTemplateType(BelongsTo::class, 'TChildModel');
                } else {
                    $types[] = $flattenType->getTemplateType(Relation::class, 'TRelatedModel');
                }
            }

            if (count($types) >= 2) {
                $childType = new UnionType(array_map(static fn (Type $type) => new ObjectType($type->getObjectClassNames()[0]), $types));
            } else {
                $childType = new ObjectType($classReflection->getName());
            }

            return new GenericObjectType($returnTypeObjectClassNames[0], [
                new ObjectType($relatedModelClassName),
                $childType,
            ]);
        } elseif (LaravelVersion::hasLaravel1115Generics()) {
            return new GenericObjectType($returnTypeObjectClassNames[0], [
                new ObjectType($relatedModelClassName),
                new ObjectType($methodReflection->getDeclaringClass()->getName()),
            ]);
        }

        return new GenericObjectType($returnTypeObjectClassNames[0], [new ObjectType($relatedModelClassName)]);
    }
}
