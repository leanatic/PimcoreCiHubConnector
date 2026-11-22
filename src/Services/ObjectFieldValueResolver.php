<?php

/**
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @license    https://choosealicense.com/licenses/gpl-3.0/ GNU General Public License v3.0
 * @copyright  Copyright (c) 2023 Brand Oriented sp. z o.o. (https://brandoriented.pl)
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 */

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Services;

use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Localizedfield;

/**
 * The text values of data object fields for the index, as the grid CSV export rendered them.
 *
 * Pimcore 2026 removed DataObject\Service::getCsvDataForObject() together with the Classic Admin. This resolves the
 * same field keys: system fields, class fields, localized fields, object bricks ("<brick>~<field>", localized brick
 * fields "?{json}~<field>") and classification store fields ("~classificationstore~<field>~<group>-<key>"). Columns of
 * Classic grid operators ("#…") have no value anymore, as their operators are gone with the Classic Admin.
 */
final class ObjectFieldValueResolver
{
    private const SYSTEM_FIELDS = [
        'id' => 'getId',
        'fullpath' => 'getRealFullPath',
        'published' => 'getPublished',
        'creationDate' => 'getCreationDate',
        'modificationDate' => 'getModificationDate',
        'filename' => 'getKey',
        'key' => 'getKey',
        'classname' => 'getClassname',
    ];

    /**
     * @param array<int, array{key: string}> $fields
     *
     * @return array<string, string>
     */
    public static function resolve(Concrete $object, string $language, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field['key']] = self::resolveField($object, $field['key'], $language);
        }

        return $values;
    }

    private static function resolveField(Concrete $object, string $field, string $language): string
    {
        if (isset(self::SYSTEM_FIELDS[$field])) {
            return (string) $object->{self::SYSTEM_FIELDS[$field]}();
        }

        $fieldDefinition = $object->getClass()->getFieldDefinition($field);
        if ($fieldDefinition instanceof Data) {
            return $fieldDefinition->getForCsvExport($object, ['language' => $language]);
        }

        if (DataObject\Service::isHelperGridColumnConfig($field)) {
            return '';
        }

        if (str_starts_with($field, '~')) {
            return self::resolveClassificationStoreField($object, $field, $language);
        }

        if (str_contains($field, '~')) {
            return self::resolveBrickField($object, $field, $language);
        }

        return self::resolveLocalizedField($object, $field, $language);
    }

    private static function resolveClassificationStoreField(Concrete $object, string $field, string $language): string
    {
        $parts = explode('~', $field);
        if (($parts[1] ?? '') !== 'classificationstore' || !isset($parts[2], $parts[3])) {
            return '';
        }

        $fieldName = $parts[2];
        [$groupId, $keyId] = array_map('intval', explode('-', $parts[3]) + [1 => 0]);
        $keyConfig = DataObject\Classificationstore\KeyConfig::getById($keyId);
        $storeDefinition = $object->getClass()->getFieldDefinition($fieldName);
        if (
            !$keyConfig instanceof DataObject\Classificationstore\KeyConfig
            || !$storeDefinition instanceof Data\Classificationstore
        ) {
            return '';
        }

        $definition = json_decode((string) $keyConfig->getDefinition(), true);
        $keyDefinition = DataObject\Classificationstore\Service::getFieldDefinitionFromJson(
            \is_array($definition) ? $definition : [],
            (string) $keyConfig->getType()
        );
        if (!$keyDefinition instanceof Data) {
            return '';
        }

        return $keyDefinition->getForCsvExport($object, ['context' => [
            'containerType' => 'classificationstore',
            'fieldname' => $fieldName,
            'groupId' => $groupId,
            'keyId' => $keyId,
            'language' => $storeDefinition->isLocalized() ? $language : 'default',
        ]]);
    }

    private static function resolveBrickField(Concrete $object, string $field, string $language): string
    {
        [$brickType, $brickField] = explode('~', $field, 2);

        $descriptor = null;
        if (str_starts_with($brickType, '?')) {
            $descriptor = json_decode(substr($brickType, 1), true);
            if (!\is_array($descriptor) || !isset($descriptor['containerKey'], $descriptor['brickfield'])) {
                return '';
            }

            $brickType = (string) $descriptor['containerKey'];
        }

        $brickDefinition = DataObject\Objectbrick\Definition::getByKey($brickType);
        $containerField = DataObject\Service::getFieldForBrickType($object->getClass(), $brickType);
        if (!$brickDefinition instanceof DataObject\Objectbrick\Definition || null === $containerField) {
            return '';
        }

        $innerContainer = (string) ($descriptor['innerContainer'] ?? 'localizedfields');
        $fieldDefinition = $descriptor
            ? self::getLocalizedFieldDefinition($brickDefinition->getFieldDefinition($innerContainer), (string) $descriptor['brickfield'])
            : $brickDefinition->getFieldDefinition($brickField);

        $container = $object->{'get' . ucfirst((string) $containerField)}();
        $brick = $container ? $container->{'get' . ucfirst($brickType)}() : null;
        if (!$fieldDefinition instanceof Data || !$brick instanceof DataObject\Objectbrick\Data\AbstractData) {
            return '';
        }

        $params = ['context' => [
            'containerType' => 'objectbrick',
            'containerKey' => $brickType,
            'fieldname' => $brickField,
        ]];

        $value = $brick;
        if ($descriptor) {
            $value = $brick->{'get' . ucfirst($innerContainer)}();
            if (!$value instanceof Localizedfield) {
                return '';
            }

            $params['language'] = $language;
        }

        return $fieldDefinition->getForCsvExport($value, $params);
    }

    private static function resolveLocalizedField(Concrete $object, string $field, string $language): string
    {
        $fieldDefinition = self::getLocalizedFieldDefinition(
            $object->getClass()->getFieldDefinition('localizedfields'),
            $field
        );
        $localizedFields = $object->get('localizedfields');
        if (!$fieldDefinition instanceof Data || !$localizedFields instanceof Localizedfield) {
            return '';
        }

        return $fieldDefinition->getForCsvExport($localizedFields, ['language' => $language]);
    }

    private static function getLocalizedFieldDefinition(?Data $container, string $field): ?Data
    {
        return $container instanceof Localizedfields ? $container->getFieldDefinition($field) : null;
    }
}
