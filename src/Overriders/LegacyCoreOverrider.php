<?php

declare(strict_types=1);

namespace FOP\Console\Overriders;

use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\FileGenerator;
use Laminas\Code\Reflection\ClassReflection;

class LegacyCoreOverrider extends AbstractOverrider implements OverriderInterface
{
    /**
     * {@inheritDoc}
     */
    public function run(): array
    {
        $success_messages = [];

        if ($this->fs->exists($this->getTargetPath()) && empty($this->getMethods())) {
            // $this->setSuccessful(); // @todo not really a success but not really a failure ...
            return ["File {$this->getTargetPath()} untouched." . PHP_EOL
                . 'It already exists and no method argument was provided', ];
        }

        // maybe this code could be improved ...
        $core_class_generator = ClassGenerator::fromReflection(new ClassReflection($this->getCoreClassName())); /* @phpstan-ignore-line */
        // Laminas doctype is not correct for phpstan, so /* @phpstan-ignore-line */ is required.
        $core_class_reflection = new ClassReflection($this->getClassName()); /* @phpstan-ignore-line */
        $override_class_generator = ClassGenerator::fromReflection($core_class_reflection);

        // re push already added methods
        $override_class_generator->addMethods($core_class_reflection->getMethods());

        if (!$this->fs->exists($this->getTargetPath())) {
            // success messages are pushed before action :/
            // not really a problem but may lead to false positives returns in the future
            // @todo can you improve it ?
            array_push($success_messages, "File '{$this->getTargetPath()}' created.");
        }

        foreach ($this->getMethods() as $method) {
            $methodReflexion = $core_class_generator->getMethod($method);
            // method not found on core file : abort
            // @todo better continue ?
            if (false === $methodReflexion) {
                $this->setUnsuccessful();

                return ["Method $method does not exist."];
            }

            // first, remove the method otherwise an error occurs - confirmation is send before, that's safe.
            $override_class_generator->removeMethod($method);
            $override_class_generator->addMethodFromGenerator($methodReflexion); /* @phpstan-ignore-line */
            array_push($success_messages, "Method '$method' added to file {$this->getTargetPath()}.");
        }

        // Create the file with the class
        $fileGenerator = new FileGenerator();
        $fileGenerator->setClass($override_class_generator);
        $this->fs->dumpFile($this->getTargetPath(), $fileGenerator->generate());

        // required otherwise generator will consider the class in the stub instead of the one in the override dir.
        \Tools::generateIndex();
        $this->setSuccessful();

        return $success_messages;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(): bool
    {
        return fnmatch('*classes/**.php', $this->getPath()) || fnmatch('*controllers/**.php', $this->getPath());
    }

    /**
     * {@inheritDoc}
     */
    public function getDangerousConsequences(): ?string
    {
        // methods provided : check is method is already reimplemented in override.
        $override_class = ClassGenerator::fromReflection(new ClassReflection($this->getClassName())); /* @phpstan-ignore-line */
        $existing_classes_warning_message = '';
        foreach ($this->getMethods() as $method) {
            $existing_classes_warning_message .= $override_class->hasMethod($method)
                ? "Method '$method' already implemented in {$this->getClassName()}" . PHP_EOL : '';
        }

        return empty($existing_classes_warning_message) ? null : $existing_classes_warning_message;
    }

    private function getTargetPath(): string
    {
        // after 'classes/' or 'controllers' (included) - probably ready to handle absolute paths
        $relative_path_start = strrpos($this->getPath(), 'classes/');
        $relative_path_start = false !== $relative_path_start
            ? $relative_path_start
            : strrpos($this->getPath(), 'controllers/'); // @todo not ready to handle absolute path for classes
        if (false === $relative_path_start) {
            throw new \Exception('no "classes/" or "controllers/" found in path.');
        }

        $file_and_folder = substr($this->getPath(), (int) $relative_path_start);

        return sprintf('override/%s', $file_and_folder);
    }

    private function getClassName(): string
    {
        return basename($this->getPath(), '.php');
    }

    private function getCoreClassName(): string
    {
        return basename($this->getPath(), '.php') . 'Core';
    }
}
