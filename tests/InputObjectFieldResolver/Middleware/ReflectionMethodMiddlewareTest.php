<?php

declare(strict_types=1);

namespace Andi\Tests\GraphQL\InputObjectFieldResolver\Middleware;

use Andi\GraphQL\Attribute\InputObjectField;
use Andi\GraphQL\Common\InputObjectFieldNameTrait;
use Andi\GraphQL\Common\LazyParserType;
use Andi\GraphQL\Common\LazyTypeByReflectionParameter;
use Andi\GraphQL\Common\LazyTypeByReflectionType;
use Andi\GraphQL\Exception\CantResolveGraphQLTypeException;
use Andi\GraphQL\InputObjectFieldResolver\InputObjectFieldResolverInterface;
use Andi\GraphQL\InputObjectFieldResolver\Middleware\MiddlewareInterface;
use Andi\GraphQL\InputObjectFieldResolver\Middleware\ReflectionMethodMiddleware;
use Andi\GraphQL\TypeRegistry;
use GraphQL\Type\Definition as Webonyx;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Spiral\Attributes\ReaderInterface;

#[CoversClass(ReflectionMethodMiddleware::class)]
#[CoversClass(InputObjectFieldNameTrait::class)]
#[UsesClass(TypeRegistry::class)]
#[UsesClass(LazyTypeByReflectionParameter::class)]
#[UsesClass(LazyTypeByReflectionType::class)]
#[UsesClass(LazyParserType::class)]
#[UsesClass(InputObjectField::class)]
final class ReflectionMethodMiddlewareTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testInstanceOf(): void
    {
        $middleware = new ReflectionMethodMiddleware(\Mockery::mock(ReaderInterface::class), new TypeRegistry());

        self::assertInstanceOf(MiddlewareInterface::class, $middleware);
    }

    public function testProcessCallNextResolver(): void
    {
        $middleware = new ReflectionMethodMiddleware(\Mockery::mock(ReaderInterface::class), new TypeRegistry());

        $nextResolver = \Mockery::mock(InputObjectFieldResolverInterface::class);
        $nextResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(new Webonyx\InputObjectField(['name' => 'foo', 'type' => Webonyx\Type::int()]));

        $middleware->process(null, $nextResolver);
    }

    #[DataProvider('getDataForProcess')]
    public function testProcess(
        array $expected,
        object $object,
        ?InputObjectField $attribute,
        string $exception = null,
    ): void {
        $reader = \Mockery::mock(ReaderInterface::class);
        $reader->shouldReceive('firstFunctionMetadata')->once()->andReturn($attribute);
        $nextResolver = \Mockery::mock(InputObjectFieldResolverInterface::class);

        $middleware = new ReflectionMethodMiddleware($reader, new TypeRegistry());

        $object = new \ReflectionClass($object);
        $field = null;
        foreach ($object->getMethods() as $field) {
            break;
        }

        if (null !== $exception) {
            $this->expectException($exception);
        }
        $inputObjectField = $middleware->process($field, $nextResolver);

        self::assertInstanceOf(Webonyx\InputObjectField::class, $inputObjectField);

        foreach ($expected as $property => $value) {
            switch ($property) {
                case 'type':
                    $type = $inputObjectField->getType();
                    if ($type instanceof Webonyx\WrappingType) {
                        $type = $type->getWrappedType();
                    }

                    self::assertSame($value, $type);
                    break;

                case 'defaultValue':
                    self::assertTrue($inputObjectField->defaultValueExists());
                    self::assertSame($value, $inputObjectField->defaultValue);
                    break;

                default:
                    self::assertSame($value, $inputObjectField->$property);
            }
        }

        if (! array_key_exists('defaultValue', $expected)) {
            self::assertFalse($inputObjectField->defaultValueExists());
        }
    }

    public static function getDataForProcess(): iterable
    {
        yield 'setFoo-remove-set-prefix' => [
            'expected' => [
                'name' => 'foo',
                'type' => Webonyx\Type::int(),
            ],
            'object' => new class {
                public function setFoo(int $i) {}
            },
            'attribute' => null,
        ];

        yield 'isActive' => [
            'expected' => [
                'name' => 'isActive',
                'type' => Webonyx\Type::boolean(),
                'defaultValue' => true,
            ],
            'object' => new class {
                public function isActive(bool $active = true) {}
            },
            'attribute' => null,
        ];

        yield 'bar-with-attribute' => [
            'expected' => [
                'name' => 'bar',
                'description' => 'bar description',
                'deprecationReason' => 'bar deprecated',
                'type' => Webonyx\Type::id(),
                'defaultValue' => 123,
            ],
            'object' => new class {
                public function setFoo(int $i) {}
            },
            'attribute' => new InputObjectField(
                name: 'bar',
                description: 'bar description',
                type: 'ID',
                deprecationReason: 'bar deprecated',
                defaultValue: 123,
            ),
        ];

        yield 'foo-with-annotation' => [
            'expected' => [
                'name' => 'foo',
                'description' => 'Description for method.',
                'deprecationReason' => 'Can\'t use this method.',
                'type' => Webonyx\Type::int(),
            ],
            'object' => new class {
                /**
                 * Description for method.
                 *
                 * @param int $i
                 *
                 * @return void
                 *
                 * @deprecated Can't use this method.
                 */
                public function setFoo(int $i) {}
            },
            'attribute' => null,
        ];

        yield 'bar-with-mixed-definition' => [
            'expected' => [
                'name' => 'bar',
                'description' => 'Description for method.',
                'deprecationReason' => 'Can\'t use this method.',
                'type' => Webonyx\Type::int(),
                'defaultValue' => 43,
            ],
            'object' => new class {
                /**
                 * Description for method.
                 *
                 * @param int $i
                 *
                 * @return void
                 *
                 * @deprecated Can't use this method.
                 */
                public function setFoo($i = 43) {}
            },
            'attribute' => new InputObjectField(name: 'bar', type: 'Int'),
        ];

        yield 'raise-exception-when-type-not-defined' => [
            'expected' => [
                'name' => 'foo',
                'type' => Webonyx\Type::int(),
            ],
            'object' => new class {
                public function setFoo($i) {}
            },
            'attribute' => null,
            'exception' => CantResolveGraphQLTypeException::class,
        ];

        yield 'raise-exception-when-method-has-several-parameters' => [
            'expected' => [
                'name' => 'foo',
                'type' => Webonyx\Type::int(),
            ],
            'object' => new class {
                public function setFoo(int $i, int $j) {}
            },
            'attribute' => null,
            'exception' => CantResolveGraphQLTypeException::class,
        ];
    }
}
