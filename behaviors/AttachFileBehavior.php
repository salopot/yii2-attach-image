<?php
namespace salopot\attach\behaviors;

use Yii;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\db\BaseActiveRecord;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;
use salopot\attach\base\AfterAttachDataEvent;

class AttachFileBehavior extends Behavior
{

    const PS = '/'; //path separator

    const EVENT_AFTER_ATTACH_DATA = 'afterAttachData';

    public $attributeName = 'file';

    //events can attach
    public $attachEvents = [BaseActiveRecord::EVENT_BEFORE_INSERT, BaseActiveRecord::EVENT_BEFORE_UPDATE];

    public $attachErrorMessage = 'Upload file canceled by error';

    protected $_relativeDir = null;

    public function setRelativeDir($value)
    {
        $this->_relativeDir = ltrim(FileHelper::normalizePath($value, self::PS), self::PS);
    }

    public function getRelativeDir()
    {
        if ($this->_relativeDir === null) {
            $this->_relativeDir = 'upload/files/' . $this->getModelBasedDir();
        }
        return $this->_relativeDir;
    }

    public function getModelBasedDir() {
        return strtolower(basename(str_replace('\\', self::PS, $this->owner->className())) . self::PS . $this->attributeName);
    }

    protected $_basePath = '@webroot';

    public function setBasePath($value)
    {
        $this->_basePath = FileHelper::normalizePath($value);
    }

    protected function getBasePath()
    {
        return Yii::getAlias($this->_basePath);
    }

    protected $_baseUrl = '@web';

    public function setBaseUrl($value)
    {
        $this->_baseUrl = $value;
    }

    protected function getBaseUrl()
    {
        return Yii::getAlias($this->_baseUrl);
    }

    protected $_directoryLevel = 2;

    public function setDirectoryLevel($value)
    {
        if (is_numeric($value) && $value >= 0 && $value <= 16)
            $this->_directoryLevel = (int)$value;
    }

    public function getDirectoryLevel()
    {
        return $this->_directoryLevel;
    }

    public function getAttribute()
    {
        return $this->owner->getAttribute($this->attributeName);
    }

    public function setAttribute($value)
    {
        $this->owner->setAttribute($this->attributeName, $value);
    }

    public function getHasAttachLink()
    {
        $value = $this->getAttribute();
        return !empty($value);
    }

    protected static function pathByName($fileName, $level = 0)
    {
        $path = '';
        for ($i = 0; $i < $level; $i++) {
            $path .= substr($fileName, $i * 2, 2) . self::PS;
        }
        return $path . $fileName;
    }

    protected function getRelativePath()
    {
        if ($this->getHasAttachLink()) {
            return $this->getRelativeDir() . self::PS . self::pathByName($this->getAttribute(), $this->getDirectoryLevel());
        } else
            return false;
    }

    protected function getDirPath()
    {
        return $this->getBasePath() . self::PS . $this->getRelativeDir();
    }

    public function getUrl()
    {
        if ($this->getHasAttachLink() && $this->getBaseUrl() !== false) {
            return $this->getBaseUrl() . self::PS . $this->getRelativePath();
        }
        return false;
    }

    public function getPath()
    {
        if ($this->getHasAttachLink()) {
            $path = $this->getBasePath() . self::PS . $this->getRelativePath();
            if (self::PS !== DIRECTORY_SEPARATOR) {
                $path = str_replace(self::PS, DIRECTORY_SEPARATOR, $path);
            }
            return $path;
        } else
            return false;
    }

    public function getHasAttachData()
    {
        return $this->getHasAttachLink() && file_exists($this->getPath());
    }

    protected static function genUniqueFileName($path, $extension, $level = 0, $salt = '')
    {
        do {
            $fileName = md5(uniqid($salt, true));
            if (!empty($extension)) $fileName .= '.' . $extension;
        } while (file_exists($path . self::PS . self::pathByName($fileName, $level)));
        return $fileName;
    }

    public function generateAttr($extension)
    {
        $filePath = self::genUniqueFileName($this->getDirPath(), $extension, $this->getDirectoryLevel(), $this->owner->className());
        $this->setAttribute($filePath);
    }

    protected $_uploadedFile = null;

    public function getUploadedFile()
    {
        if ($this->_uploadedFile === null)
            $this->_uploadedFile = UploadedFile::getInstance($this->owner, $this->attributeName);
        return $this->_uploadedFile;
    }

    //for set on tabular input or MultiFileUpload
    public function setUploadedFile(UploadedFile $value)
    {
        $this->_uploadedFile = $value;
    }

    public function getHasUploadedFile()
    {
        return $this->getUploadedFile() !== null;
    }

    protected $_processUploadedData = null;

    public function setProcessUploadData($callBack)
    {
        if (is_callable($callBack))
            $this->_processUploadedData = $callBack;
        else
            throw new InvalidConfigException('ProcessUploadData must be callback: function($behavior, $uploadedFile){ return bool }');
    }

    protected function clearEmptyLevelPath($dirPath)
    {
        $level = $this->getDirectoryLevel();
        if ($level > 0) {
            for ($i = 0; $i < $level && @rmdir($dirPath); $i++) {
                $dirPath = dirname($dirPath);
            }
        }
    }

    /**
     * @param $filePath
     * Delete file and empty dirs created by directoryLevel
     */
    protected function clearFilePath($filePath)
    {
        @unlink($filePath);
        $this->clearEmptyLevelPath(dirname($filePath));
    }


    public function clearAttachData()
    {
        if ($this->getHasAttachData()) {
            $this->clearFilePath($this->getPath());
        }
    }

    public function clear() {
        $this->clearAttachData();
        $this->setAttribute(null);
    }

    public static function getExtensionByMimeType($mimeType) {
        $extensions = FileHelper::getExtensionsByMimeType($mimeType);
        if (empty($extensions)) return false;
        return mb_strtolower($extensions[0], 'utf-8');
    }

    public static function getUploadedFileExtension($uploadedFile)
    {
        if ($uploadedFile->extension) return strtolower($uploadedFile->extension);
        $mimeType = FileHelper::getMimeType($uploadedFile->tempName);
        if ($mimeType === null) $mimeType = $uploadedFile->type;
        return static::getExtensionByMimeType($mimeType);
    }

    public function attachFile($file, $moveFile = false) {
        if (!$this->owner->getIsNewRecord()) {
            $this->clearAttachData();
        }
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if (empty($extension)) {
            $extension = static::getExtensionByMimeType( FileHelper::getMimeType($file) );
        }
        $this->generateAttr($extension);
        $filePath = $this->getPath();
        if (!FileHelper::createDirectory(dirname($filePath))) return false;
        if ($moveFile) {
            if(!rename($file, $filePath)) return false;
        } else {
            if (!copy($file, $filePath)) return false;
        }
        return true;
    }

    public function attachContent($data, $mimeType) {
        if (!$this->owner->getIsNewRecord()) {
            $this->clearAttachData();
        }
        $extension = static::getExtensionByMimeType($mimeType);
        if ($extension === false) return false;
        $this->generateAttr($extension);
        $filePath = $this->getPath();
        if (!FileHelper::createDirectory(dirname($filePath))) return false;
        if (@file_put_contents($filePath, $data) === false) return false;
        return true;
    }

    protected function attachUploadedFile()
    {
        $file = $this->getUploadedFile();
        if ($file->getHasError()) return false;
        if (!$this->owner->getIsNewRecord()) {
            $this->clearAttachData();
        }
        if ($this->_processUploadedData === null) {
            $extension = self::getUploadedFileExtension($file);
            $this->generateAttr($extension);
            $filePath = $this->getPath();
            if (!FileHelper::createDirectory(dirname($filePath))) return false;
            if (!$file->saveAs($filePath)) return false;
        } else
            if (!call_user_func($this->_processUploadedData, $this, $file)) return false;

        $this->owner->trigger(self::EVENT_AFTER_ATTACH_DATA, new AfterAttachDataEvent(['behavior' => $this, 'uploadedFile' => $file]));
        return true;
    }

    public function events()
    {
        return [
            BaseActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            BaseActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            BaseActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }


    public function beforeSave($event)
    {
        //restore attribute value cleared by file validator
        $newValue = $this->getAttribute();
        if (!$this->owner->getIsNewRecord() && empty($newValue)) {
            $oldValue = $this->owner->getOldAttribute($this->attributeName);

            //clear if has no file
            if (!empty($oldValue)) {
                $this->setAttribute($oldValue);
                if (!$this->getHasAttachData()) {
                    $this->setAttribute($newValue);
                }
            }
        }
        if (in_array($event->name, $this->attachEvents)) {

            if ($this->getHasUploadedFile()) {
                if (!$this->attachUploadedFile()) {
                    $event->isValid = false;
                    $this->owner->addError($this->attributeName, $this->attachErrorMessage);
                }
            }

        }
    }

    public function afterDelete($event)
    {
        $this->clearAttachData();
    }
}