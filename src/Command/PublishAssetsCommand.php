<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Command;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\MediaTypes;
use Areanet\ContentflyAdmin\Version;
use Areanet\PIM\Classes\Command\CustomCommand;
use Areanet\PIM\Classes\Kernel\Paths;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Copies the package's assets into the document root so the web server delivers them directly.
 *
 * ENTIRELY OPTIONAL. Without it the admin works — `Controller\AssetController` serves every
 * asset through PHP, which is what makes the package usable straight after `composer require`.
 * This command trades that convenience for speed in production: a file that exists on disk is
 * handed out by the web server before the request ever reaches the front controller.
 *
 * WHY THE TARGET SITS NEXT TO THE ADMIN PREFIX AND NEVER UNDER IT: the front controller only
 * runs for a path that is not an existing directory. A copy inside `<docroot><prefix>/` would
 * turn the prefix into a real directory and the admin's own entry page would stop answering.
 * `AdminManager::validateAssetPath()` rejects that, and ADR 0007 carries the reasoning.
 *
 * IT DELIVERS THE SAME SET OF FILES AS THE ROUTE, which is why it asks `Asset\MediaTypes` what
 * to copy instead of copying the directory wholesale. Were the two sets to differ, switching a
 * project between the paths would change which assets exist — and the README beside the
 * vendored modules would land in a public directory.
 *
 * `CustomCommand` PREFIXES THE NAME WITH `custom:`, so this runs as
 * `php bin/console.php custom:admin:assets-publish`. The prefix is not ours to choose: the
 * core's `ConsoleManager::addCommand()` only accepts a `CustomCommand`.
 */
final class PublishAssetsCommand extends CustomCommand
{
    private const SOURCE = __DIR__.'/../../public';

    protected function configure(): void
    {
        $this
            ->setName('admin:assets-publish')
            ->setDescription('Copies the admin assets into the document root so the web server serves them directly.')
            ->addOption(
                'remove',
                null,
                InputOption::VALUE_NONE,
                'Deletes the copy of this version again, so the admin falls back to serving through its route.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager   = $this->application()[AdminManager::CONTAINER_KEY];
        $assetPath = $manager->getAssetPath();

        /*
         * WITHOUT THE OPTION THE COMMAND REFUSES TO WRITE ANYTHING — and says what to set.
         * Copying files the admin would then not link to produces a directory nobody reads and
         * no visible effect at all, which is a worse outcome than an error message.
         */
        if ($assetPath === '') {
            $output->writeln('<error>The option "assetPath" is not set, so the admin serves its assets through its route.</error>');
            $output->writeln('');
            $output->writeln('Set it in custom/app.php, then run this command again:');
            $output->writeln('');
            $output->writeln("    \$app['adminManager'] = new \\Areanet\\ContentflyAdmin\\AdminManager(\$app, [");
            $output->writeln("        'assetPath' => '/admin-assets',");
            $output->writeln('    ]);');
            $output->writeln('');
            $output->writeln('The path must not lie under the admin prefix — see ADR 0007.');

            return self::FAILURE;
        }

        $assetRoot = Paths::project().$assetPath;
        $target    = $assetRoot.'/'.Version::VERSION;

        if ($input->getOption('remove')) {
            return $this->remove($target, $assetRoot, $output);
        }

        return $this->publish($target, $output);
    }

    /**
     * Writes every deliverable asset to the target, and reports what actually changed.
     *
     * IDEMPOTENT BY COMPARISON, NOT BY TIMESTAMP. A second run writes only files whose content
     * differs, so running it twice is both harmless and silent — and after a package update it
     * reports exactly the files the update touched.
     */
    private function publish(string $target, OutputInterface $output): int
    {
        $assets = self::assets();

        if ($assets === array()) {
            $output->writeln('<error>No deliverable asset found in the package. That cannot be right — the installation looks incomplete.</error>');

            return self::FAILURE;
        }

        $written   = 0;
        $unchanged = 0;

        foreach ($assets as $relative => $source) {
            $destination = $target.'/'.$relative;
            $directory   = dirname($destination);

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                $output->writeln(sprintf('<error>Cannot create the directory %s.</error>', $directory));

                return self::FAILURE;
            }

            $contents = file_get_contents($source);

            if ($contents === false) {
                $output->writeln(sprintf('<error>Cannot read %s.</error>', $source));

                return self::FAILURE;
            }

            if (is_file($destination) && file_get_contents($destination) === $contents) {
                ++$unchanged;

                continue;
            }

            if (file_put_contents($destination, $contents) === false) {
                $output->writeln(sprintf('<error>Cannot write %s.</error>', $destination));

                return self::FAILURE;
            }

            $output->writeln(sprintf('  written   %s', $relative));
            ++$written;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>%d file(s) written, %d unchanged, in %s</info>',
            $written,
            $unchanged,
            $target
        ));
        $output->writeln('The admin now links to this copy; the route stays in place as a fallback for other versions.');

        /*
         * THE PREREQUISITE IS NAMED, because getting it wrong looks like a broken admin.
         * The copy is only reachable if the web server hands out an existing file before it
         * reaches the front controller — Apache does, through the `!-f` condition in the
         * project's `.htaccess`. PHP's built-in development server does not: it routes every
         * request through `index.php`, so with `assetPath` set there, no asset arrives at all.
         */
        $output->writeln('');
        $output->writeln('<comment>This needs a web server that serves an existing file before the front controller.</comment>');
        $output->writeln('<comment>Apache does, via the !-f rule in .htaccess. PHP\'s built-in server (php -S) does not —</comment>');
        $output->writeln('<comment>leave "assetPath" unset while developing against it, or no asset will be delivered.</comment>');

        return self::SUCCESS;
    }

    /**
     * Deletes the copy of this version again — the way back to serving through the route.
     *
     * ONLY THIS VERSION'S DIRECTORY, and only files this command would have written. Anything
     * else in there was put there by somebody else, and a command that cleans up more than it
     * created is one nobody dares to run.
     *
     * THE EMPTY DIRECTORIES GO TOO, up to and including the asset path itself. Leaving an empty
     * directory behind in the document root would mean the way back does not quite arrive: the
     * project would differ from its state before the command ever ran, and a check that compares
     * the two would report it.
     */
    private function remove(string $target, string $assetRoot, OutputInterface $output): int
    {
        if (!is_dir($target)) {
            $output->writeln(sprintf('<info>Nothing to remove — %s does not exist.</info>', $target));

            return self::SUCCESS;
        }

        $removed = 0;

        foreach (array_keys(self::assets()) as $relative) {
            $file = $target.'/'.$relative;

            if (is_file($file) && unlink($file)) {
                ++$removed;
            }
        }

        /*
         * The directories go only when they are empty, and the loop runs deepest-first.
         * `rmdir()` on a non-empty directory simply fails, which is the behaviour wanted here:
         * anything left behind is not ours.
         */
        $directories = array();
        foreach (array_keys(self::assets()) as $relative) {
            $directory = dirname($target.'/'.$relative);
            while (strlen($directory) > strlen($target)) {
                $directories[$directory] = strlen($directory);
                $directory               = dirname($directory);
            }
        }

        arsort($directories);
        foreach (array_keys($directories) as $directory) {
            @rmdir($directory);
        }

        @rmdir($target);

        /*
         * Up to the asset path itself, deepest first. `rmdir()` fails on a non-empty directory,
         * which is exactly the stopping condition wanted — another version's copy next door
         * keeps its parent alive and nothing of it is touched.
         */
        $directory = dirname($target);

        while (strlen($directory) >= strlen($assetRoot)) {
            @rmdir($directory);
            $directory = dirname($directory);
        }

        $output->writeln(sprintf('<info>%d file(s) removed from %s</info>', $removed, $target));
        $output->writeln('The admin serves its assets through its route again. Remove the "assetPath" option to make that permanent.');

        return self::SUCCESS;
    }

    /**
     * Every deliverable asset of the package, as `path relative to the asset root` => `absolute path`.
     *
     * @return array<string,string>
     */
    private static function assets(): array
    {
        $root = realpath(self::SOURCE);

        if ($root === false) {
            return array();
        }

        $found    = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || MediaTypes::forFile($file->getPathname()) === null) {
                continue;
            }

            $found[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
        }

        ksort($found);

        return $found;
    }
}
