<?php

namespace ImportBundle\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\MyClass;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\MyFieldCollection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportService
{
    private const MAIN_SELECT_OPTIONS = ['option1', 'option2', 'option3'];
    private const FIELD_COLLECTION_SELECT_OPTIONS = ['valid', 'invalid'];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function import(string $path, OutputInterface $output): bool
    {
        try {
            if (!file_exists($path)) {
                throw new \Exception("File not found: $path");
            }

            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();

            $rows = $sheet->toArray(null, true, true, true);
            $header = array_shift($rows);
            $success = true;
            $stats = ['success' => 0, 'errors' => 0];

            // папка /imported если не существует
            $this->ensureImportFolderExists($output);

            foreach ($rows as $index => $row) {
                $line = $index + 2;

                $data = [];
                foreach ($header as $key => $fieldName) {
                    $data[$fieldName] = $row[$key] ?? null;
                }

                try {
                    $object = $this->processObject($data, $line, $output);
                    if ($object) {
                        $stats['success']++;
                    }
                } catch (\Exception $e) {
                    $this->logError($e, $line, $output);
                    $success = false;
                    $stats['errors']++;
                }
            }

            $this->printStats($stats, $output);
            return $success;

        } catch (\Exception $e) {
            $this->logger->error("Ошибка чтения файла: " . $e->getMessage());
            $output->writeln("<error>Фатальная ошибка импорта: {$e->getMessage()}</error>");
            return false;
        }
    }

    private function ensureImportFolderExists(OutputInterface $output): void
    {
        $parent = DataObject::getByPath('/imported');
        if (!$parent) {
            try {
                $parent = new DataObject\Folder();
                $parent->setKey('imported');
                $parent->setParent(DataObject::getByPath('/'));
                $parent->save();
                $msg = "Автоматически создана папка /imported";
                $output->writeln("<info>$msg</info>");
                $this->logger->info($msg);
            } catch (\Exception $e) {
                $msg = "Не удалось создать папку /imported: " . $e->getMessage();
                $output->writeln("<error>$msg</error>");
                $this->logger->error($msg);
                throw $e;
            }
        }
    }

    private function processObject(array $data, int $line, OutputInterface $output): ?MyClass
    {
        $object = !empty($data['id']) ? MyClass::getById($data['id']) : null;

        if (!$object instanceof MyClass) {
            $object = new MyClass();
            $object->setKey('imported-' . uniqid());
            $object->setParent(DataObject::getByPath('/imported'));
        }

        $this->setBasicFields($object, $data);
        $this->processFieldCollection($object, $data);

        $object->save();
        $msg = "Строка $line: объект сохранён успешно (ID: {$object->getId()})";
        $output->writeln("<info>$msg</info>");
        $this->logger->info($msg);

        return $object;
    }

    private function setBasicFields(MyClass $object, array $data): void
    {
        if (isset($data['name'])) {
            $object->setName($data['name']);
        }

        if (isset($data['number'])) {
            $object->setNumber((int)$data['number']);
        }

        if (isset($data['select'])) {
            if (!in_array($data['select'], self::MAIN_SELECT_OPTIONS, true)) {
                throw new \Exception(
                    "Недопустимое значение select: '{$data['select']}'. " .
                    "Допустимые значения: " . implode(', ', self::MAIN_SELECT_OPTIONS)
                );
            }
            $object->setSelect($data['select']);
        }
    }

    private function processFieldCollection(MyClass $object, array $data): void
    {
        if (empty($data['MyFieldCollection'])) {
            $this->logger->debug("Пустое значение FieldCollection");
            return;
        }

        try {
            $jsonString = trim($data['MyFieldCollection']);
            $this->logger->debug("Processing FieldCollection JSON: " . $jsonString);

            $itemsData = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($itemsData)) {
                throw new \RuntimeException("FieldCollection должен содержать массив объектов");
            }

            $collection = new Fieldcollection();
            $itemCount = 0;

            foreach ($itemsData as $index => $itemData) {
                if (!is_array($itemData)) {
                    $this->logger->warning(sprintf(
                        "Элемент FieldCollection #%d не является массивом",
                        $index + 1
                    ));
                    continue;
                }

                try {
                    $item = new MyFieldCollection();

                    $field1 = $itemData['field1'] ?? null;
                    if ($field1 !== null) {
                        $item->setField1((string)$field1);
                    }

                    if (array_key_exists('selectField', $itemData)) {
                        $value = $itemData['selectField'];
                        if (!in_array($value, self::FIELD_COLLECTION_SELECT_OPTIONS, true)) {
                            throw new \DomainException(sprintf(
                                "Недопустимое значение selectField: '%s'",
                                $value
                            ));
                        }
                        $item->setSelectField($value);
                    }

                    $collection->add($item);
                    $itemCount++;

                } catch (\Exception $e) {
                    $this->logger->error(sprintf(
                        "Ошибка обработки элемента #%d: %s",
                        $index + 1,
                        $e->getMessage()
                    ));
                    throw $e;
                }
            }

            if ($itemCount > 0) {
                $object->setMyFieldCollection($collection);
                $this->logger->debug(sprintf(
                    "Успешно обработано %d элементов FieldCollection",
                    $itemCount
                ));
            }

        } catch (\JsonException $e) {
            throw new \RuntimeException("Ошибка декодирования JSON: " . $e->getMessage());
        }
    }

    private function logError(\Exception $e, int $line, OutputInterface $output): void
    {
        $msg = "Строка $line: " . $e->getMessage();
        $output->writeln("<error>$msg</error>");
        $this->logger->error($msg);
    }

    private function printStats(array $stats, OutputInterface $output): void
    {
        $output->writeln("\n<comment>Итог импорта:</comment>");
        $output->writeln("<comment>Успешно: {$stats['success']}, Ошибок: {$stats['errors']}</comment>");
    }
}
