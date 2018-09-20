<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * EmailBlacklistImportCommand
 *
 * Handles the actions for email blacklist import related tasks.
 * Most of the logic is borrowed from the web interface importer.
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link https://www.mailwizz.com/
 * @copyright 2013-2018 MailWizz EMA (https://www.mailwizz.com)
 * @license https://www.mailwizz.com/license/
 * @since 1.5.2
 */

class EmailBlacklistImportCommand extends ConsoleCommand
{
    /**
     * @var string the folder path from where we should load files
     */
    public $folder_path = '';

    /**
     * @var int max amount of files to process from the folder
     */
    public $folder_process_files = 10;

    /**
     * @var string the path where the import file is located
     */
    public $file_path = '';

    /**
     * @var int is verbose
     */
    public $verbose = 0;

    /**
     * @var array for external access maybe?
     */
    public $lastMessage = array();

    /**
     * @return int
     * @throws CException
     */
    public function actionFolder()
    {
        if (empty($this->folder_path)) {
            $this->folder_path = Yii::getPathOfAlias('common.runtime.email-blacklist-import-queue');
        }

        if (!is_dir($this->folder_path) || !is_readable($this->folder_path)) {
            return $this->renderMessage(array(
                'result'  => 'error',
                'message' => Yii::t('email_blacklist', 'Call this command with the --folder_path=XYZ param where XYZ is the full path to the folder you want to monitor.'),
                'return'  => 1,
            ));
        }

        $this->renderMessage(array(
            'result'  => 'info',
            'message' => 'The folder path is: '. $this->folder_path,
        ));

        $files  = FileSystemHelper::readDirectoryContents($this->folder_path, true);
        $pcntl  = CommonHelper::functionExists('pcntl_fork') && CommonHelper::functionExists('pcntl_waitpid');
        $childs = array();

        if ($pcntl) {
            
            // close the external connections
            $this->setExternalConnectionsActive(false);
        }

        if (count($files) > (int)$this->folder_process_files) {
            $files = array_slice($files, (int)$this->folder_process_files);
        }

        $this->renderMessage(array(
            'result'  => 'info',
            'message' => 'Found '. count($files) . ' files (some of them might be already processing)',
        ));

        foreach ($files as $file) {
            if (!$pcntl) {
                $this->processFile($file);
                continue;
            }

            //
            sleep(5); // this allows the files to get a start ahead of each other
            $pid = pcntl_fork();
            if($pid == -1) {
                continue;
            }

            // Parent
            if ($pid) {
                $childs[] = $pid;
            }

            // Child
            if (!$pid) {
                $this->processFile($file);
                Yii::app()->end();
            }
        }

        if ($pcntl) {
            while (count($childs) > 0) {
                foreach ($childs as $key => $pid) {
                    $res = pcntl_waitpid($pid, $status, WNOHANG);
                    if($res == -1 || $res > 0) {
                        unset($childs[$key]);
                    }
                }
                sleep(1);
            }
        }

        return 0;
    }

    /**
     * @param $file
     * @return int
     */
    protected function processFile($file)
    {
        $this->renderMessage(array(
            'result'  => 'info',
            'message' => 'Processing: ' . $file,
        ));

        $lockName = sha1($file);
        if (!Yii::app()->mutex->acquire($lockName, 5)) {
            return $this->renderMessage(array(
                'result'  => 'info',
                'message' => 'Cannot acquire lock for processing: ' . $file,
                'return'  => 1,
            ));
        }

        if (!is_file($file)) {
            Yii::app()->mutex->release($lockName);
            return $this->renderMessage(array(
                'result'  => 'info',
                'message' => 'The file: "' . $file . '" was removed by another process!',
                'return'  => 1,
            ));
        }

        try {

            $fileName  = basename($file);
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);

            Yii::app()->hooks->doAction('console_command_email_blacklist_import_before_process', new CAttributeCollection(array(
                'command'    => $this,
                'importType' => $extension,
                'filePath'   => $file,
            )));

            $this->processCsv(array(
                'file_path' => $file,
            ));

            Yii::app()->hooks->doAction('console_command_email_blacklist_import_after_process', new CAttributeCollection(array(
                'command'    => $this,
                'importType' => $extension,
                'filePath'   => $file,
            )));

            if (in_array($extension, array('csv')) && is_file($file)) {

                // remove the file
                @unlink($file);

                if ($server = DeliveryServer::pickServer()) {
                    $options         = Yii::app()->options;
                    $command         = Yii::app()->command;
                    $fileName        = basename($file);
                    $overviewUrl     = $options->get('system.urls.backend_absolute_url') . 'email-blacklist/index';
                    $emailTemplate   = $options->get('system.email_templates.common');

                    $users = User::model()->findAllByAttributes(array(
                        'removable' => User::TEXT_NO
                    ));

                    foreach ($users as $user) {
                        $viewData      = compact('overviewUrl', 'fileName', 'user');
                        $emailBody     = $command->renderFile(Yii::getPathOfAlias('console.views.email-blacklist-import-finished').'.php', $viewData, true);
                        $emailTemplate = str_replace('[CONTENT]', $emailBody, $emailTemplate);

                        $emailParams = array(
                            'subject' => Yii::t('email_blacklist', 'Email blacklist import has finished!'),
                            'body'    => $emailTemplate,
                            'to'      => array($user->email => $user->email),
                        );

                        $server->sendEmail($emailParams);
                    }
                }
                //
            }
            
        } catch (Exception $e) {

            $this->stdout(__LINE__ . ': ' .  $e->getMessage());
            Yii::log($e->getMessage(), CLogger::LEVEL_ERROR);
        }

        Yii::app()->mutex->release($lockName);

        $this->renderMessage(array(
            'result'  => 'info',
            'message' => 'The file: "' . $file . '" was processed!',
        ));
    }

    /**
     * @return int
     * @throws CException
     */
    public function actionCsv()
    {
        Yii::app()->hooks->doAction('console_command_email_blacklist_import_before_process', new CAttributeCollection(array(
            'command'    => $this,
            'importType' => 'csv',
        )));

        $result = $this->processCsv(array(
            'file_path' => $this->file_path,
        ));

        Yii::app()->hooks->doAction('console_command_email_blacklist_import_after_process', new CAttributeCollection(array(
            'command'    => $this,
            'importType' => 'csv',
        )));

        return $result;
    }

    /**
     * @param array $params
     * @return int
     * @throws CException
     */
    protected function processCsv(array $params)
    {
        if (empty($params['file_path']) || !is_file($params['file_path'])) {
            return $this->renderMessage(array(
                'result'  => 'error',
                'message' => Yii::t('email_blacklist', 'Call this command with the --file_path=/some/file.csv param where /some/file.csv is the full path to the csv file to be imported.'),
                'return'  => 1,
            ));
        }
        
        ini_set('auto_detect_line_endings', true);

        $delimiter = StringHelper::detectCsvDelimiter($params['file_path']);
        $file      = new SplFileObject($params['file_path']);
        $file->setCsvControl($delimiter);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE | SplFileObject::READ_AHEAD);
        $columns = $file->current(); // the header

        if (empty($columns)) {
            return $this->renderMessage(array(
                'result'  => 'error',
                'message' => Yii::t('email_blacklist', 'Your file does not contain the header with the fields title!'),
                'return'  => 1,
            ));
        }

        if (!empty($columns)) {
            $columns = array_map('strtolower', $columns);
            if (array_search('email', $columns) === false) {
                $columns = null;
            }
        }

        if (empty($columns)) {
            return $this->renderMessage(array(
                'result'  => 'error',
                'message' => Yii::t('email_blacklist', 'Your file does not contain the header with the fields title!'),
                'return'  => 1,
            ));
        }

        $ioFilter     = Yii::app()->ioFilter;
        $columnCount  = count($columns);
        $totalRecords = 0;
        $totalImport  = 0;

        while (!$file->eof()) {

            ++$totalRecords;

            $row = $file->fgetcsv();
            if (empty($row)) {
                continue;
            }

            $row = $ioFilter->stripPurify($row);
            $rowCount = count($row);

            if ($rowCount == 0) {
                continue;
            }

            $isEmpty = true;
            foreach ($row as $value) {
                if (!empty($value)) {
                    $isEmpty = false;
                    break;
                }
            }

            if ($isEmpty) {
                continue;
            }

            if ($columnCount > $rowCount) {
                $fill = array_fill($rowCount, $columnCount - $rowCount, '');
                $row  = array_merge($row, $fill);
            } elseif ($rowCount > $columnCount) {
                $row = array_slice($row, 0, $columnCount);
            }

            $model = new EmailBlacklist();
            $data  = new CMap(array_combine($columns, $row));
            $model->email  = $data->itemAt('email');
            $model->reason = $data->itemAt('reason');
            
            $this->stdout('Processing the email: ' . $model->email);
            
            if ($model->save()) {
                $totalImport++;
            }
            
            unset($model, $data);
        }
        
        return $this->renderMessage(array(
            'result'  => 'success',
            'message' => Yii::t('email_blacklist', 'The import process has finished!'),
            'return'  => 0,
        ));
    }

    /**
     * @param array $data
     * @return int
     */
    protected function renderMessage($data = array())
    {
        if (isset($data['type']) && in_array($data['type'], array('success', 'error'))) {
            $this->lastMessage = $data;
        }

        if (isset($data['message']) && $this->verbose) {
            $out = '['.date('Y-m-d H:i:s').'] - ';
            if (isset($data['type'])) {
                $out .= '[' . strtoupper($data['type']) . '] - ';
            }
            $out .= strip_tags(str_replace(array('<br />', '<br/>', '<br>'), PHP_EOL, $data['message'])) . PHP_EOL;
            echo $out;
        }

        if (isset($data['return']) || array_key_exists('return', $data)) {
            return (int)$data['return'];
        }
    }
}
