<?php
/**
 * @author    jan huang <bboyjanhuang@gmail.com>
 * @copyright 2016
 *
 * @see      https://www.github.com/janhuang
 * @see      http://www.fast-d.cn/
 */

namespace FastD\Migration;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table as SymfonyTable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Migrate
 * @package FastD\Migration\Console
 */
class Migrate extends Command
{
    public function configure()
    {
        $this
            ->setName('migrate')
            ->setDescription('Migration database to php')
            ->addArgument('behavior', InputArgument::REQUIRED, 'migration behavior')
            ->addArgument('table', InputArgument::OPTIONAL, 'migration table name', null)
            ->addOption('path', 'p', InputOption::VALUE_OPTIONAL, 'tables path', './')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $file = getcwd().'/migrate.yml';
        if (! file_exists($file)) {
            $helper = $this->getHelper('question');
            $host = $helper->ask($input, $output, new Question('MySQL host (<info>127.0.0.1</info>)?', '127.0.0.1'));
            $user = $helper->ask($input, $output, new Question('MySQL user (<info>root</info>)?', 'root'));
            $password = $helper->ask($input, $output, new Question('MySQL password (<info>null</info>)?', null));
            $dbname = $helper->ask($input, $output, new Question('MySQL database (<info>null</info>)?', null));
            $charset = $helper->ask($input, $output, new Question('MySQL charset (<info>utf8</info>)?', 'utf8'));
            $content = Yaml::dump(
                [
                    'host' => $host,
                    'user' => $user,
                    'pass' => $password,
                    'dbname' => $dbname,
                    'charset' => $charset,
                ]
            );
            file_put_contents($file, $content);
        }
        $path = realpath($input->getParameterOption(['--path', '-p']));
        if (! file_exists($path)) {
            mkdir($path, 0755, true);
        }
        $tableName = $input->getArgument('table');
        $schema = new Schema();
        $config = $schema->getConfig();
        $output->writeln(Yaml::dump($config));
        switch ($input->getArgument('behavior')) {
            case 'run':
                foreach (glob($path.'/*.php') as $file) {
                    $migration = pathinfo($file, PATHINFO_FILENAME);
                    include_once $file;
                    $migration = new $migration();
                    if ($migration instanceof Migration) {
                        $table = $migration->setUp();
                        if ($schema->update($table)) {
                            $output->writeln(sprintf('  <info>==</info> Table <info>"%s"</info> <comment>migrating</comment> <info>done.</info>', $table->getTableName()));
                        } else {
                            $output->writeln(sprintf('  <info>==</info> Table <info>"%s"</info> <comment>nothing todo.</comment>', $table->getTableName()));
                        }
                        $this->renderTableSchema($output, $table)->render();
                    } else {
                        $output->writeln(sprintf('<comment>Warning: Mission table "%s"</comment>', $migration));
                    }
                }
                break;
            case 'dump':
                $tables = $schema->extract($tableName);
                foreach ($tables as $table) {
                    $name = $this->classRename($table);
                    $file = $path . '/' . $name . '.php';
                    $content = $this->dump($table);
                    $contentHash = hash('md5', $content);
                    if (!file_exists($file) || (file_exists($file) && $contentHash !== hash_file('md5', $file))) {
                        file_put_contents($file, $content);
                    }

                    $output->writeln(sprintf('  <info>==</info> Table <info>"%s"</info> <comment>dumping</comment> <info>done.</info>', $table->getTableName()));
                    $this->renderTableSchema($output, $table)->render();
                }
                break;
        }

        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param Table $table
     * @return SymfonyTable
     */
    protected function renderTableSchema(OutputInterface $output, Table $table)
    {
        $t = new SymfonyTable($output);
        $t->setHeaders(array('Field', 'Type', 'Nullable', 'Key', 'Default', 'Extra'));
        foreach ($table->getColumns() as $column) {
            $t->addRow(
                [
                    $column->getName(),
                    $column->getType().($column->getLength() <= 0 ? '' : '('.$column->getLength().')'),
                    $column->isNullable() ? 'YES' : 'NO',
                    null === $column->getKey() ? '' : $column->getKey()->getKey(),
                    $column->getDefault(),
                    (null == $column->getComment()) ? '' : ('comment:'. $column->getComment()),
                ]
            );
        }

        return $t;
    }

    /**
     * @param Table $table
     * @return string
     */
    protected function classRename(Table $table)
    {
        $name = $table->getTableName();
        if (strpos($name, '_')) {
            $arr = explode('_', $name);
            $name = array_shift($arr);
            foreach ($arr as $value) {
                $name .= ucfirst($value);
            }
        }
        return ucfirst($name);
    }

    /**
     * @param Table $table
     * @return string
     */
    protected function dump(Table $table)
    {
        $name = $this->classRename($table);

        $code = ['$table'];
        foreach ($table->getColumns() as $column) {
            $code[] = str_repeat(' ', 12) . '->addColumn(new Column(\'' . $column->getName() . '\', \'' . $column->getType() . '\'))';
        }
        $code[] = str_repeat(' ', 8) . ';';

        $codeString = implode(PHP_EOL, $code);

        return <<<MIGRATION
<?php

use \FastD\Migration\Migration;
use \FastD\Migration\Column;
use \FastD\Migration\Table;


class {$name} extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        \$table = new Table('{$table->getTableName()}');

        {$codeString}

        return \$table;
    }

    /**
     * {@inheritdoc}
     */
    public function dataSet()
    {
        
    }
}
MIGRATION;
    }
}