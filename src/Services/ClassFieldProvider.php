<?php

/**
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @license    https://choosealicense.com/licenses/gpl-3.0/ GNU General Public License v3.0
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 */

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Services;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Objectbrick\Definition as ObjectbrickDefinition;

/**
 * The fields of a class that the config editor offers for a class schema ("columnConfig"), with the keys that
 * ObjectFieldValueResolver reads: system fields, class fields, localized fields and object brick fields
 * ("<brick>~<field>"). Containers without a single value (field collections, blocks, classification stores) are left
 * out.
 */
final class ClassFieldProvider
{
    private const SYSTEM_FIELDS = ['id', 'key', 'fullpath', 'published', 'creationDate', 'modificationDate'];

    private const SKIPPED_TYPES = ['fieldcollections', 'block', 'classificationstore'];

    /**
     * @return list<array{key: string, label: string, type: string, group: string, integer?: bool}>
     */
    public function getFields(ClassDefinition $classDefinition): array
    {
        $fields = [];
        foreach (self::SYSTEM_FIELDS as $key) {
            $fields[] = ['key' => $key, 'label' => $key, 'type' => 'system', 'group' => 'system'];
        }

        foreach ($classDefinition->getFieldDefinitions() as $fieldDefinition) {
            if ($fieldDefinition instanceof Data\Localizedfields) {
                foreach ($fieldDefinition->getFieldDefinitions() as $localizedField) {
                    $fields[] = $this->describe($localizedField, (string) $localizedField->getName(), 'localized');
                }
            } elseif ($fieldDefinition instanceof Data\Objectbricks) {
                array_push($fields, ...$this->getBrickFields($fieldDefinition));
            } elseif (!\in_array($fieldDefinition->getFieldtype(), self::SKIPPED_TYPES, true)) {
                $fields[] = $this->describe($fieldDefinition, (string) $fieldDefinition->getName(), 'class');
            }
        }

        return $fields;
    }

    /**
     * @return list<array{key: string, label: string, type: string, group: string, integer?: bool}>
     */
    private function getBrickFields(Data\Objectbricks $objectbricks): array
    {
        $fields = [];
        foreach ($objectbricks->getAllowedTypes() as $brickType) {
            $brick = ObjectbrickDefinition::getByKey((string) $brickType);
            if (!$brick instanceof ObjectbrickDefinition) {
                continue;
            }

            foreach ($brick->getFieldDefinitions() as $brickField) {
                // Localized brick fields have keys of their own ("?{json}~<field>"), not offered here
                if ($brickField instanceof Data\Localizedfields || \in_array($brickField->getFieldtype(), self::SKIPPED_TYPES, true)) {
                    continue;
                }

                $fields[] = $this->describe($brickField, $brickType.'~'.$brickField->getName(), (string) $brickType);
            }
        }

        return $fields;
    }

    /**
     * @return array{key: string, label: string, type: string, group: string, integer?: bool}
     */
    private function describe(Data $fieldDefinition, string $key, string $group): array
    {
        $field = [
            'key' => $key,
            'label' => (string) ($fieldDefinition->getTitle() ?: $fieldDefinition->getName()),
            'type' => $fieldDefinition->getFieldtype(),
            'group' => $group,
        ];

        if ($fieldDefinition instanceof Data\Numeric) {
            $field['integer'] = (bool) $fieldDefinition->getInteger();
        }

        return $field;
    }
}
