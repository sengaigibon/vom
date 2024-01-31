<?php

declare(strict_types=1);

namespace Zolex\VOM;

use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zolex\VOM\Metadata\Factory\ModelMetadataFactoryInterface;
use Zolex\VOM\Metadata\ModelMetadata;

final class VersatileObjectMapper implements NormalizerInterface, DenormalizerInterface
{
    public const ROOT_FALLBACK = 'root_fallback';
    private const ROOT_DATA = '__root_data';
    private const PATH = '__normalization_path';

    public function __construct(
        private readonly ModelMetadataFactoryInterface $modelMetadataFactory,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    private array $filters;

    public function getSupportedTypes(?string $format): array
    {
        return ['array'];
    }

    public function supportsNormalization(mixed $data, string $format = null, array $context = []): bool
    {
        return (null === $format || 'array' === $format) && is_object($data);
    }

    public function normalize(mixed $object, string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $format ??= 'array';
        if ('object' === $format) {
            throw new InvalidArgumentException('To get an object use the "array" format and then call the toObject($array) on the VersatileObjectMapper.');
        }

        if ('array' !== $format) {
            throw new InvalidArgumentException('Format can only be "array"');
        }

        if (!is_object($object)) {
            throw new \InvalidArgumentException('First argument must be an object.');
        }

        $context[self::PATH] ??= [];
        $data = $context[AbstractNormalizer::OBJECT_TO_POPULATE] ?? [];
        if (!$metadata = $this->modelMetadataFactory->create(get_class($object))) {
            return (array) $object;
        }

        foreach ($metadata->getProperties() as $property) {
            $propertyName = $property->getName();
            try {
                $value = $this->propertyAccessor->getValue($object, $propertyName);
            } catch (\Throwable) {
                continue;
            }

            $accessor = explode('.', $property->getAccessor());

            if (null === $value && (true === ($context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ?? false))) {
                continue;
            }

            if ($property->isArray()) {
                foreach ($value as $index => $item) {
                    $childChain = $property->isNested() ? array_merge($property->isRoot() ? [$index] : $context[self::PATH], $accessor, [$index]) : [$index];
                    $data = $this->normalize($item, $format, array_merge($context, [AbstractNormalizer::OBJECT_TO_POPULATE => $data, self::PATH => $childChain]));
                }
            } elseif ($property->isModel()) {
                $childChain = $property->isNested() ? array_merge($property->isRoot() ? [] : $context[self::PATH], $accessor) : [];
                $data = $this->normalize($value, $format, array_merge($context, [AbstractNormalizer::OBJECT_TO_POPULATE => $data, self::PATH => $childChain]));
            } else {
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format($property->getDateTimeFormat());
                }
                $childAccessor = sprintf('[%s]', implode('][', array_merge($context[self::PATH], $accessor)));
                $this->propertyAccessor->setValue($data, $childAccessor, $value);
            }

            unset($value);
            unset($accessor);
            unset($childAccessor);
            unset($childChain);
        }

        return $data;
    }

    public function toObject(array|object $data): object|array
    {
        if (is_array($data) && array_is_list($data)) {
            $array = [];
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $array[$key] = $this->toObject($value);
                } else {
                    $array[$key] = $value;
                }
            }

            return $array;
        } else {
            $object = new \stdClass();
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $object->{$key} = $this->toObject($value);
                } else {
                    $object->{$key} = $value;
                }
            }

            return $object;
        }
    }

    public function supportsDenormalization(mixed $data, string $type, string $format = null, array $context = []): bool
    {
        return (null === $format || 'array' === $format) && class_exists($type) && (is_array($data) || is_object($data));
    }

    public function denormalize(mixed $data, string $type, string $format = null, array $context = []): mixed
    {
        if (is_array($data)) {
            if (array_is_list($data) && str_ends_with($type, '[]')) {
                $array = [];
                foreach ($data as $item) {
                    $array[] = $this->denormalize($item, substr($type, 0, -2), $format, $context);
                }

                return $array;
            }

            $data = $this->toObject($data);
        }

        $context[self::ROOT_FALLBACK] ??= false;
        $context[self::ROOT_DATA] ??= $data;
        $context[AbstractNormalizer::GROUPS] ??= [];
        if (!is_array($context[AbstractNormalizer::GROUPS])) {
            $context[AbstractNormalizer::GROUPS] = [$context[AbstractNormalizer::GROUPS]];
        }

        $model = new $type();
        $metadata = $this->modelMetadataFactory->create($type);
        foreach ($metadata->getProperties() as $property) {
            if (count($context[AbstractNormalizer::GROUPS])
                && !count(array_intersect($property->getGroups(), $context[AbstractNormalizer::GROUPS]))) {
                continue;
            }
            $accessor = $property->getAccessor();
            try {
                $value = $this->propertyAccessor->getValue($data, $accessor);
            } catch (\Throwable) {
                if (!$property->isNested() || true === $context[self::ROOT_FALLBACK]) {
                    $value = $context[self::ROOT_DATA];
                } elseif ($property->isNested() && $property->isRoot()) {
                    try {
                        $value = $this->propertyAccessor->getValue($context[self::ROOT_DATA], $accessor);
                    } catch (\Throwable) {
                        $value = null;
                    }
                } else {
                    $value = null;
                }
            }

            if (null === $value && (true === ($context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ?? false))) {
                continue;
            }

            if (null !== $value && ($property->isModel() || $property->isArray())) {
                $value = $this->denormalize($value, $property->getType(), $format, $context);
            } elseif ($property->isBool()) {
                if ($property->isFlag()) {
                    if (is_array($data)) {
                        // common flag
                        if (in_array($accessor, $data, true)) {
                            $value = true;
                        } elseif (in_array('!'.$accessor, $data, true)) {
                            $value = false;
                        } else {
                            $value = null;
                        }
                    } elseif (is_object($data)) {
                        // custom flag nested value
                        $value = !$property->isFalse($value);
                    }
                } else {
                    $value = $property->isTrue($value);
                }
            } elseif ($property->isDateTime() && is_string($value)) {
                $class = $property->getType();
                $value = new $class($value);
            }

            try {
                $this->propertyAccessor->setValue($model, $property->getName(), $value);
            } catch (\Throwable) {
            }
        }

        return $model;
    }

    /**
     * @codeCoverageIgnore
     * Unused, just here as a backup :P
     */
    private function getNormalizedFields(string|ModelMetadata $metadata, array $context = []): array
    {
        $fields = [];
        $context[self::PATH] ??= [];
        $context[AbstractObjectNormalizer::GROUPS] ??= [];
        if (is_string($metadata)) {
            $metadata = $this->modelMetadataFactory->create($metadata);
        }

        foreach ($metadata->getProperties() as $property) {
            if ($property->isNested()) {
                $accessChain = array_merge($context[self::PATH], [$property->getAccessor()]);
            } else {
                $accessChain = [$property->getAccessor()];
            }

            if (count(array_intersect($property->getGroups(), $context[AbstractObjectNormalizer::GROUPS]))) {
                $fields[] = implode('.', $accessChain);
            }

            if ($nestedModelMetadata = $property->getModelMetadata()) {
                $fields = array_merge($fields, $this->getNormalizedFields($nestedModelMetadata, $context));
            }
        }

        return $fields;
    }
}
