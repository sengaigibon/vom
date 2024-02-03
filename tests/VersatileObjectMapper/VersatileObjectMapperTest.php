<?php

/*
 * This file is part of the VOM package.
 *
 * (c) Andreas Linden <zlx@gmx.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zolex\VOM\Test\VersatileObjectMapper;

use PHPUnit;
use Prophecy\PhpUnit\ProphecyTrait;
use Zolex\VOM\Serializer\Factory\VersatileObjectMapperFactory;
use Zolex\VOM\Serializer\VersatileObjectMapper;
use Zolex\VOM\Test\Fixtures\Address;
use Zolex\VOM\Test\Fixtures\Arrays;
use Zolex\VOM\Test\Fixtures\Booleans;
use Zolex\VOM\Test\Fixtures\Calls;
use Zolex\VOM\Test\Fixtures\CommonFlags;
use Zolex\VOM\Test\Fixtures\ConstructorArguments;
use Zolex\VOM\Test\Fixtures\DateAndTime;
use Zolex\VOM\Test\Fixtures\NestedName;
use Zolex\VOM\Test\Fixtures\Person;
use Zolex\VOM\Test\Fixtures\PropertyPromotion;

/**
 * Base test with a fresh instance of the VOM for each test.
 */
class VersatileObjectMapperTest extends PHPUnit\Framework\TestCase
{
    use ProphecyTrait;

    protected static VersatileObjectMapper $serializer;

    protected function setUp(): void
    {
        self::$serializer = VersatileObjectMapperFactory::create();
    }

    public function testDecoratedMethods(): void
    {
        $serialized = self::$serializer->serialize([2], 'json', [1]);
        $this->assertEquals('[2]', $serialized);
        $deserialized = self::$serializer->deserialize('[]', DateAndTime::class, 'json');
        $this->assertEquals(new DateAndTime(), $deserialized);

        $supportedTypes = self::$serializer->getSupportedTypes('json');
        $this->assertEquals(['*' => false], $supportedTypes);

        $supportsNormalization = self::$serializer->supportsNormalization(new DateAndTime());
        $this->assertTrue($supportsNormalization);
        $normalized = self::$serializer->normalize(new \DateTime('2010-01-01 00:00:00'), 'json');
        $this->assertEquals('2010-01-01T00:00:00+00:00', $normalized);

        $supportsDenormalization = self::$serializer->supportsDenormalization(['dateTime' => '2010-01-01 10:10:10'], DateAndTime::class);
        $this->assertTrue($supportsDenormalization);

        $denormalized = self::$serializer->denormalize([2], DateAndTime::class);
        $this->assertEquals(new DateAndTime(), $denormalized);
    }

    public function testBooleansUninitialized(): void
    {
        $data = [];

        /* @var Booleans $booleans */
        $booleans = self::$serializer->denormalize($data, Booleans::class);
        $this->assertFalse(isset($booleans->bool));
        $this->assertFalse(isset($booleans->nullableBool));

        $normalized = self::$serializer->normalize($booleans);
        $this->assertArrayNotHasKey('bool', $normalized);
        $this->assertArrayHasKey('nullableBool', $normalized);
        $this->assertNull($normalized['nullableBool']);
    }

    public function testNullableBooleanExplicitlyNull()
    {
        $data = [
            'nullableBool' => null,
        ];

        /* @var Booleans $booleans */
        $booleans = self::$serializer->denormalize($data, Booleans::class);
        $this->assertFalse(isset($booleans->bool));
        $this->assertNull($booleans->nullableBool);
    }

    /**
     * @dataProvider provideBooleans
     */
    public function testBooleans($data, $expected): void
    {
        /* @var Booleans $booleans */
        $booleans = self::$serializer->denormalize($data, Booleans::class);
        $normalized = self::$serializer->normalize($booleans);
        $this->assertEquals($expected, $normalized);
    }

    public function provideBooleans(): iterable
    {
        yield [
            [
                // bool has no explicit true-value configured
                'bool' => 1,
                'nullableBool' => 0,
                'stringBool' => 'yeah',
                'anotherBool' => 'FALSE',
            ],
            [
                // to the result will be true for anything in the default true-values list
                'bool' => true,
                'nullableBool' => false,
                'stringBool' => 'yeah',
                'anotherBool' => 'FALSE',
            ],
        ];

        yield [
            [
                'bool' => true,
                'nullableBool' => 'NO',
                'stringBool' => 'nope',
                'anotherBool' => 'TRUE',
            ],
            [
                'bool' => true,
                'nullableBool' => false,
                'stringBool' => 'nope',
                'anotherBool' => 'TRUE',
            ],
        ];

        yield [
            [
                'nullableBool' => null,
                // VOM property explicitly requires the string 'TRUE'
                'anotherBool' => true,
            ],
            [
                'nullableBool' => null,
                // so the bool true becomes the property's false-value!
                'anotherBool' => 'FALSE',
            ],
        ];

        yield [
            [],
            [
                // only bools that are nullable can be null :P
                // rest must be uninitialized
                'nullableBool' => null,
            ],
        ];
    }

    public function testDateAndTime(): void
    {
        $data = [];

        /* @var DateAndTime $dateAndTime */
        $dateAndTime = self::$serializer->denormalize($data, DateAndTime::class);
        $this->assertFalse(isset($dateAndTime->dateTime));
        $this->assertFalse(isset($dateAndTime->dateTimeImmutable));

        $data = [
            'dateTime' => '2024-02-03 13:05:00',
            'dateTimeImmutable' => '1985-01-20 12:34:56',
        ];

        /* @var DateAndTime $dateAndTime */
        $dateAndTime = self::$serializer->denormalize($data, DateAndTime::class);
        $this->assertTrue(isset($dateAndTime->dateTime));
        $this->assertTrue(isset($dateAndTime->dateTimeImmutable));

        $this->assertEquals($data['dateTime'], $dateAndTime->dateTime->format('Y-m-d H:i:s'));
        $this->assertEquals($data['dateTimeImmutable'], $dateAndTime->dateTimeImmutable->format('Y-m-d H:i:s'));
    }

    /**
     * @dataProvider provideCommonFlags
     */
    public function testCommonFlags($data, $expected)
    {
        /* @var CommonFlags $commonFlags */
        $commonFlags = self::$serializer->denormalize($data, CommonFlags::class);

        // when the nullable flagC is not passed, it should stay null!
        if (!\in_array('flagC', $data) && !\in_array('!flagC', $data)) {
            $this->assertNull($commonFlags->flagC);
        }

        $normalized = self::$serializer->normalize($commonFlags, 'json');
        $this->assertIsArray($normalized);
        $this->assertCount(\count($expected), $normalized);
        $this->assertTrue(array_is_list($normalized));
        foreach ($expected as $expectedFlag) {
            $this->assertTrue(\in_array($expectedFlag, $normalized));
        }
    }

    public function provideCommonFlags(): iterable
    {
        // flagD has a default value true, so it will
        // always be there unless explicitly passed as !flagD

        yield [
            ['flagA', '!flagB'],
            ['flagA', '!flagB', 'flagD'],
        ];

        yield [
            ['!flagA', 'flagB', 'flagC'],
            ['!flagA', 'flagB', 'flagC', 'flagD'],
        ];

        yield [
            ['flagC'],
            ['flagC', 'flagD'],
        ];

        yield [
            ['!flagC', 'flagA'],
            ['!flagC', 'flagD', 'flagA'],
        ];

        yield [
            ['!flagC', '!flagD'],
            ['!flagC', '!flagD'],
        ];

        yield [
            [],
            ['flagD'],
        ];
    }

    public function testAccessor(): void
    {
        $data = [
            'nested' => [
                'firstname' => 'Andreas',
                'deeper' => [
                    'surname' => 'Linden',
                ],
            ],
        ];

        /* @var NestedName $nestedName */
        $nestedName = self::$serializer->denormalize($data, NestedName::class);
        $this->assertEquals($data['nested']['firstname'], $nestedName->firstname);
        $this->assertEquals($data['nested']['deeper']['surname'], $nestedName->lastname);
    }

    public function testArrayOfModels(): void
    {
        $data = [
            [
                'nested' => [
                    'firstname' => 'Andreas',
                    'deeper' => [
                        'surname' => 'Linden',
                    ],
                ],
            ],
            [
                'nested' => [
                    'firstname' => 'Javier',
                    'deeper' => [
                        'surname' => 'Caballero',
                    ],
                ],
            ],
            [
                'nested' => [
                    'firstname' => 'Peter',
                    'deeper' => [
                        'surname' => 'Enis',
                    ],
                ],
            ],
        ];

        /* @var array|NestedName[] $nestedName */
        $nestedNames = self::$serializer->denormalize($data, NestedName::class.'[]');

        $this->assertIsArray($nestedNames);
        $this->assertCount(3, $nestedNames);
        foreach ($data as $index => $item) {
            $this->assertEquals($item['nested']['firstname'], $nestedNames[$index]->firstname);
            $this->assertEquals($item['nested']['deeper']['surname'], $nestedNames[$index]->lastname);
        }
    }

    public function testConstruct(): void
    {
        $data = [
            'id' => 42,
            'name' => 'Peter Pan',
            'nullable' => false,
            'default' => true,
        ];

        $constructed = self::$serializer->denormalize($data, ConstructorArguments::class);
        $this->assertEquals(42, $constructed->getId());
        $this->assertEquals('Peter Pan', $constructed->getName());
        $this->assertFalse($constructed->getNullable());
        $this->assertTrue($constructed->getDefault());
    }

    public function testPropertyPromotion(): void
    {
        $data = [
            'id' => 42,
            'name' => 'Peter Pan',
        ];

        $constructed = self::$serializer->denormalize($data, PropertyPromotion::class);
        $this->assertEquals(42, $constructed->getId());
        $this->assertEquals('Peter Pan', $constructed->getName());
        $this->assertNull($constructed->getNullable());
        $this->assertTrue($constructed->getDefault());

        $data = [
            'id' => 42,
            'name' => 'Peter Pan',
            'default' => false,
            'nullable' => true,
        ];

        $constructed = self::$serializer->denormalize($data, PropertyPromotion::class);
        $this->assertEquals(42, $constructed->getId());
        $this->assertEquals('Peter Pan', $constructed->getName());
        $this->assertTrue($constructed->getNullable());
        $this->assertfalse($constructed->getDefault());
    }

    public function testMethodCalls(): void
    {
        $data = [
            'id' => 42,
            'name' => 'Peter Enis',
        ];

        $calls = self::$serializer->denormalize($data, Calls::class);
        $this->assertEquals(42, $calls->getId());
        $this->assertEquals('Peter Enis', $calls->getName());
    }

    public function testArrayOnRoot(): void
    {
        $data = [
            ['dateTime' => '2024-01-01 00:00:00'],
            ['dateTime' => '2024-01-02 00:00:00'],
            ['dateTime' => '2024-01-03 00:00:00'],
        ];

        /** @var DateAndTime[] $arrayOfDateAndTime */
        $arrayOfDateAndTime = self::$serializer->denormalize($data, DateAndTime::class.'[]');
        $this->assertCount(3, $arrayOfDateAndTime);
        $this->assertEquals('2024-01-01 00:00:00', $arrayOfDateAndTime[0]->dateTime->format('Y-m-d H:i:s'));
        $this->assertEquals('2024-01-02 00:00:00', $arrayOfDateAndTime[1]->dateTime->format('Y-m-d H:i:s'));
        $this->assertEquals('2024-01-03 00:00:00', $arrayOfDateAndTime[2]->dateTime->format('Y-m-d H:i:s'));
    }

    public function testRecursiveStructures(): void
    {
        $data = [
            'dateTimeList' => [
                ['dateTime' => '2024-01-01 00:00:00'],
            ],
            'recursiveList' => [
                [
                    'dateTimeList' => [
                        ['dateTime' => '2024-01-02 00:00:00'],
                        ['dateTime' => '2024-01-03 00:00:00'],
                    ],
                    'recursiveList' => [
                        [
                            'dateTimeList' => [
                                ['dateTime' => '2024-01-03 00:00:00'],
                                ['dateTime' => '2024-01-04 00:00:00'],
                                ['dateTime' => '2024-01-05 00:00:00'],
                            ],
                            'recursiveList' => [
                                [
                                    'dateTimeList' => [
                                        ['dateTime' => '2024-01-06 00:00:00'],
                                        ['dateTime' => '2024-01-07 00:00:00'],
                                        ['dateTime' => '2024-01-08 00:00:00'],
                                        ['dateTime' => '2024-01-09 00:00:00'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'dateTimeList' => [
                        ['dateTime' => '2024-01-10 00:00:00'],
                    ],
                    'recursiveList' => [
                        [
                            'dateTimeList' => [
                                ['dateTime' => '2024-01-11 00:00:00'],
                                ['dateTime' => '2024-01-12 00:00:00'],
                            ],
                            'recursiveList' => [
                                [
                                    'dateTimeList' => [
                                        ['dateTime' => '2024-01-13 00:00:00'],
                                        ['dateTime' => '2024-01-14 00:00:00'],
                                        ['dateTime' => '2024-01-15 00:00:00'],
                                    ],
                                    'recursiveList' => [
                                        [
                                            'dateTimeList' => [
                                                ['dateTime' => '2024-01-16 00:00:00'],
                                                ['dateTime' => '2024-01-17 00:00:00'],
                                                ['dateTime' => '2024-01-18 00:00:00'],
                                                ['dateTime' => '2024-01-19 00:00:00'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $items = self::$serializer->denormalize($data, Arrays::class);
        $data2 = self::$serializer->normalize($items);

        $this->assertEquals($data, $data2);
    }

    /**
     * @dataProvider createNestedModelsDataProvider
     */
    public function testCreateNestedModels(array $data, string $className, string|array $groups, object $expectedModel)
    {
        $model = self::$serializer->denormalize($data, $className, null, ['groups' => $groups]);
        $this->assertEquals($expectedModel, $model);
    }

    public function createNestedModelsDataProvider(): iterable
    {
        yield [
            [
                'id' => 42,
                'name' => [
                    'firstname' => 'The',
                    'lastname' => 'Dude',
                ],
            ],
            Person::class,
            ['id'],
            new Person(id: 42),
        ];

        yield [
            [
                'id' => 42,
                'name' => [
                    'firstname' => 'The',
                    'lastname' => 'Dude',
                ],
                'int_age' => 38,
                'contact_email' => 'some@mail.to',
                'address' => [
                    'street' => 'nowhere',
                ],
            ],
            Person::class,
            ['standard'],
            new Person(id: 42, firstname: 'The', lastname: 'Dude', age: 38, email: 'some@mail.to'),
        ];

        yield [
            [
                'id' => 42,
                'int_age' => 42,
                'contact_email' => 'some@mail.to',
                'address' => [
                    'street' => 'Fireroad',
                    'housenumber' => '666',
                    'zipcode' => '56070',
                    'city' => 'Hell',
                ],
                'bool_awesome' => 'y',
                'hilarious' => 'OFF',
                'flags' => [
                    'delicious' => 'delicious',
                    'holy' => 'holy',
                ],
            ],
            Person::class,
            ['extended'],
            new Person(
                id: 42,
                age: 42,
                email: 'some@mail.to',
                isAwesome: true,
                isHilarious: false,
                address: new Address(
                    street: 'Fireroad',
                    houseNo: 666,
                    zip: 56070,
                    city: 'Hell',
                ),
            ),
        ];

        yield [
            [
                'id' => 43,
                'int_age' => 42,
                'contact_email' => 'some@mail.to',
                'address' => [
                    'street' => 'Fireroad',
                    'housenumber' => '32',
                    'zipcode' => '50210',
                    'city' => 'Hell',
                ],
                'bool_awesome' => 'true',
                'hilarious' => 'ON',
                'flags' => [
                    'delicious' => 'delicious',
                ],
            ],
            Person::class,
            ['id', 'isHoly', 'isHilarious'],
            new Person(
                id: 43,
                isHilarious: true,
            ),
        ];

        yield [
            [
                'id' => 44,
                'hilarious' => 'ON',
                'address' => [
                    'street' => 'Fireroad',
                    'housenumber' => '213456',
                    'zipcode' => '98765',
                    'city' => 'Dunkel',
                ],
            ],
            Person::class,
            ['id', 'address'],
            new Person(
                id: 44,
                address: new Address(
                    street: 'Fireroad',
                    houseNo: '213456',
                    zip: '98765',
                    city: 'Dunkel',
                ),
            ),
        ];

        yield [
            [
                'id' => 45,
                'address' => [
                    'street' => 'Elmstreet',
                    'housenumber' => '666',
                ],
            ],
            Person::class,
            ['address'],
            new Person(
                address: new Address(
                    street: 'Elmstreet',
                    houseNo: '666',
                    zip: null,
                    city: null,
                    country: null
                ),
            ),
        ];
    }
}
