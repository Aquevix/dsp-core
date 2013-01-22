<?php

/**
 * @category   DreamFactory
 * @package    DreamFactory
 * @subpackage CommonFileSvc
 * @copyright  Copyright (c) 2009 - 2012, DreamFactory (http://www.dreamfactory.com)
 * @license    http://www.dreamfactory.com/license
 */

class CommonFileSvc extends CommonService implements iRestHandler
{
    /**
     * @var FileManager|BlobFileManager
     */
    protected $fileRestHandler;

    /**
     * @param array $config
     * @throws Exception
     */
    public function __construct($config)
    {
        parent::__construct($config);

        // Validate storage setup
        $store_name = Utilities::getArrayValue('storage_name', $config, '');
        if (empty($store_name)) {
            throw new Exception("Error creating common file service. No storage name given.");
        }
        try {
            $type = Utilities::getArrayValue('type', $config, '');
            switch (strtolower($type)) { // remote blob storage or native disk
            case 'remote file storage':
                $this->fileRestHandler = new BlobFileManager($config, $store_name);
                break;
            default:
                $this->fileRestHandler = new FileManager($store_name);
                break;
            }
        }
        catch (Exception $ex) {
            throw new Exception("Error creating document storage.\n{$ex->getMessage()}");
        }
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        parent::__destruct();
    }

    /**
     * @param string $path
     * @return void
     * @throws Exception
     */
    public function streamFile($path)
    {
        try {
            $this->fileRestHandler->streamFile($path);
        }
        catch (Exception $ex) {
            throw $ex;
        }
    }

    // Controller based methods

    /**
     * @return array
     * @throws Exception
     */
    public function actionGet()
    {
        $this->checkPermission('read');
        $result = array();
        $path = Utilities::getArrayValue('resource', $_GET, '');
        $path_array = (!empty($path)) ? explode('/', $path) : array();
        if (empty($path) || empty($path_array[count($path_array) - 1])) {
            // list from root
            // or if ending in '/' then resource is a folder
            try {
                if (isset($_REQUEST['properties'])) {
                    // just properties of the directory itself
                    $result = $this->fileRestHandler->getFolderProperties($path);
                }
                else {
                    $asZip = Utilities::boolval(Utilities::getArrayValue('zip', $_REQUEST, false));
                    if ($asZip) {
                        $zipFileName = $this->fileRestHandler->getFolderAsZip($path);
                        $fd = fopen ($zipFileName, "r");
                        if ($fd) {
                            $fsize = filesize($zipFileName);
                            $path_parts = pathinfo($zipFileName);
                            header("Content-type: application/zip");
                            header("Content-Disposition: filename=\"".$path_parts["basename"]."\"");
                            header("Content-length: $fsize");
                            header("Cache-control: private"); //use this to open files directly
                            while(!feof($fd)) {
                                $buffer = fread($fd, 2048);
                                echo $buffer;
                            }
                        }
                        fclose($fd);
                        unlink($zipFileName);
                        Yii::app()->end();
                    }
                    else {
                        $full_tree = (isset($_REQUEST['full_tree'])) ? true : false;
                        $include_files = true;
                        $include_folders = true;
                        if (isset($_REQUEST['files_only'])) {
                            $include_folders = false;
                        }
                        elseif (isset($_REQUEST['folders_only'])) {
                            $include_files = false;
                        }
                        $result = $this->fileRestHandler->getFolderContent($path, $include_files, $include_folders, $full_tree);
                    }
                }
            }
            catch (Exception $ex) {
                throw new Exception("Failed to retrieve folder content for '$path''.\n{$ex->getMessage()}");
            }
        }
        else {
            // resource is a file
            try {
                if (isset($_REQUEST['properties'])) {
                    // just properties of the file itself
                    $content = Utilities::boolval(Utilities::getArrayValue('content', $_REQUEST, false));
                    $result = $this->fileRestHandler->getFileProperties($path, $content);
                }
                else {
                    $download = Utilities::boolval(Utilities::getArrayValue('download', $_REQUEST, false));
                    if ($download) {
                        // stream the file, exits processing
                        $this->fileRestHandler->downloadFile($path);
                    }
                    else {
                        // stream the file, exits processing
                        $this->fileRestHandler->streamFile($path);
                    }
                }
            }
            catch (Exception $ex) {
                throw new Exception("Failed to retrieve file '$path''.\n{$ex->getMessage()}");
            }
        }

        return $result;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function actionPost()
    {
        $this->checkPermission('create');
        $path = Utilities::getArrayValue('resource', $_GET, '');
        $path_array = (!empty($path)) ? explode('/', $path) : array();
        $result = array();
        if (empty($path) || empty($path_array[count($path_array) - 1])) {
            // if ending in '/' then create files or folders in the directory
            if (isset($_SERVER['HTTP_X_FILE_NAME']) && !empty($_SERVER['HTTP_X_FILE_NAME'])) {
                // html5 single posting for file create
                $name = $_SERVER['HTTP_X_FILE_NAME'];
                $fullPathName = $path . $name;
                try {
                    $content = Utilities::getPostData();
                    if (empty($content)) {
                        // empty post?
                        error_log("Empty content in create file $fullPathName.");
                    }
                    $contentType = Utilities::getArrayValue('CONTENT_TYPE', $_SERVER, '');
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    if (FileUtilities::isZipContent($contentType) && $extract) {
                        // need to extract zip file and move contents to storage
                        $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                        $tmpName = $tempDir  . $name;
                        file_put_contents($tmpName, $content);
                        $zip = new ZipArchive();
                        if (true === $zip->open($tmpName)) {
                            $this->fileRestHandler->extractZipFile($path, $zip, $clean);
                        }
                        else {
                            throw new Exception('Error opening temporary zip file.');
                        }
                    }
                    else {
                        $this->fileRestHandler->writeFile($fullPathName, $content, false, true);
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to create file $fullPathName.\n{$ex->getMessage()}");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            elseif (isset($_SERVER['HTTP_X_FOLDER_NAME']) && !empty($_SERVER['HTTP_X_FOLDER_NAME'])) {
                // html5 single posting for folder create
                $name = $_SERVER['HTTP_X_FOLDER_NAME'];
                $fullPathName = $path . $name;
                try {
                    $content = Utilities::getPostDataAsArray();
                    $this->fileRestHandler->createFolder($fullPathName, true, $content, true);
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to create folder $fullPathName.\n{$ex->getMessage()}");
                }
                $result = array('folder' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            elseif (isset($_FILES['files']) && !empty($_FILES['files'])) {
                // older html multi-part/form-data post, single or multiple files
                $files = $_FILES['files'];
                $out = array();
                if (!is_array($files['error'])) {
                    // single file
                    $name = $files['name'];
                    $fullPathName = $path . $name;
                    $out[0] = array('name' => $name, 'path' => $fullPathName);
                    $error = $files['error'];
                    if ($error == UPLOAD_ERR_OK) {
                        $tmpName = $files['tmp_name'];
                        $contentType = $files['type'];
                        $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                        try {
                            if (FileUtilities::isZipContent($contentType) && $extract) {
                                // need to extract zip file and move contents to storage
                                $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                                $zip = new ZipArchive();
                                if (true === $zip->open($tmpName)) {
                                    $this->fileRestHandler->extractZipFile($path, $zip, $clean);
                                }
                                else {
                                    throw new Exception('Error opening temporary zip file.');
                                }
                            }
                            else {
                                $this->fileRestHandler->moveFile($fullPathName, $tmpName, true);
                            }
                        }
                        catch (Exception $ex) {
                            $out[0]['error'] = array('code' => $ex->getCode(),
                                                     'message' => "Failed to create file $fullPathName.\n{$ex->getMessage()}");
                        }
                    }
                    else {
                        $out[0]['error'] = array('code' => 500,
                                                 'message' => "Failed to create file $fullPathName.\n$error");
                    }
                }
                else {
                    //$files = Utilities::reorgFilePostArray($files);
                    foreach ($files['error'] as $key => $error) {
                        $name = $files['name'][$key];
                        $fullPathName = $path . $name;
                        $out[$key] = array('name' => $name, 'path' => $fullPathName);
                        if ($error == UPLOAD_ERR_OK) {
                            $tmpName = $files['tmp_name'][$key];
                            $contentType = $files['type'][$key];
                            $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                            try {
                                if (FileUtilities::isZipContent($contentType) && $extract) {
                                    // need to extract zip file and move contents to storage
                                    $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                                    $zip = new ZipArchive();
                                    if (true === $zip->open($tmpName)) {
                                        $this->fileRestHandler->extractZipFile($path, $zip, $clean);
                                    }
                                    else {
                                        throw new Exception('Error opening temporary zip file.');
                                    }
                                }
                                else {
                                    $this->fileRestHandler->moveFile($fullPathName, $tmpName, true);
                                }
                            }
                            catch (Exception $ex) {
                                $out[$key]['error'] = array('code' => $ex->getCode(),
                                                            'message' => "Failed to create file $fullPathName.\n{$ex->getMessage()}");
                            }
                        }
                        else {
                            $out[$key]['error'] = array('code' => 500,
                                                        'message' => "Failed to create file $fullPathName.\n$error");
                        }
                    }
                }
                $result = array('file' => $out);
            }
            else {
                // possibly xml or json post either of files or folders to create, copy or move
                try {
                    $data = Utilities::getPostDataAsArray();
                    if (empty($data)) {
                        // create folder from resource path
                        $this->fileRestHandler->createFolder($path);
                        $result = array('folder' => array(array('path' => $path)));
                    }
                    else {
                        $out = array('folder' => array(), 'file' => array());
                        $folders = Utilities::getArrayValue('folder', $data, null);
                        if (empty($folders)) {
                            $folders = (isset($data['folders']['folder']) ? $data['folders']['folder'] : null);
                        }
                        if (!empty($folders)) {
                            if (!isset($folders[0])) {
                                // single folder, make into array
                                $folders = array($folders);
                            }
                            foreach ($folders as $key=>$folder) {
                                $name = Utilities::getArrayValue('name', $folder, '');
                                if (isset($folder['source_path'])) {
                                    // copy or move
                                    $srcPath = $folder['source_path'];
                                    if (empty($name)) {
                                        $name = FileUtilities::getNameFromPath($srcPath);
                                    }
                                    $fullPathName = $path . $name . '/';
                                    $out['folder'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    try {
                                        $this->fileRestHandler->copyFolder($fullPathName, $srcPath, true);
                                        $deleteSource = Utilities::boolval(Utilities::getArrayValue('delete_source', $folder, false));
                                        if ($deleteSource) {
                                            $this->fileRestHandler->deleteFolder($srcPath, true);
                                        }
                                    }
                                    catch (Exception $ex) {
                                        $out['folder'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                                else {
                                    $fullPathName = $path . $name;
                                    $content = Utilities::getArrayValue('content', $folder, '');
                                    $isBase64 = Utilities::boolval(Utilities::getArrayValue('is_base64', $folder, false));
                                    if ($isBase64) {
                                        $content = base64_decode($content);
                                    }
                                    $out['folder'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    try {
                                        $this->fileRestHandler->createFolder($fullPathName, true, $content);
                                    }
                                    catch (Exception $ex) {
                                        $out['folder'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                            }
                        }
                        $files = Utilities::getArrayValue('file', $data, null);
                        if (empty($files)) {
                            $files = (isset($data['files']['file']) ? $data['files']['file'] : null);
                        }
                        if (!empty($files)) {
                            if (!isset($files[0])) {
                                // single file, make into array
                                $files = array($files);
                            }
                            foreach ($files as $key=>$file) {
                                $name = Utilities::getArrayValue('name', $file, '');
                                if (isset($file['source_path'])) {
                                    // copy or move
                                    $srcPath = $file['source_path'];
                                    if (empty($name)) {
                                        $name = FileUtilities::getNameFromPath($srcPath);
                                    }
                                    $fullPathName = $path . $name;
                                    $out['file'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    try {
                                        $this->fileRestHandler->copyFile($fullPathName, $srcPath, true);
                                        $deleteSource = Utilities::boolval(Utilities::getArrayValue('delete_source', $file, false));
                                        if ($deleteSource) {
                                            $this->fileRestHandler->deleteFile($srcPath);
                                        }
                                    }
                                    catch (Exception $ex) {
                                        $out['file'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                                elseif (isset($file['content'])) {
                                    $fullPathName = $path . $name;
                                    $out['file'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    $content = Utilities::getArrayValue('content', $file, '');
                                    $isBase64 = Utilities::boolval(Utilities::getArrayValue('is_base64', $file, false));
                                    if ($isBase64) {
                                        $content = base64_decode($content);
                                    }
                                    try {
                                        $this->fileRestHandler->writeFile($fullPathName, $content);
                                    }
                                    catch (Exception $ex) {
                                        $out['file'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                            }
                        }
                        $result = $out;
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to create folders or files from request.\n{$ex->getMessage()}");
                }
            }
        }
        else {
            // if ending in file name, create the file or folder
            $name = substr($path, strripos($path, '/') + 1);
            if (isset($_SERVER['HTTP_X_FILE_NAME']) && !empty($_SERVER['HTTP_X_FILE_NAME'])) {
                $x_file_name = $_SERVER['HTTP_X_FILE_NAME'];
                if (0 !== strcasecmp($name, $x_file_name)) {
                    throw new Exception("Header file name '$x_file_name' mismatched with REST resource '$name'.");
                }
                try {
                    $content = Utilities::getPostData();
                    $contentType = (isset($_SERVER['CONTENT_TYPE'])) ? $_SERVER['CONTENT_TYPE'] : '';
                    if (empty($content)) {
                        // empty post?
                        error_log("Empty content in write file $path to storage.");
                    }
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    if (FileUtilities::isZipContent($contentType) && $extract) {
                        // need to extract zip file and move contents to storage
                        $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                        $tmpName = $tempDir  . $name;
                        file_put_contents($tmpName, $content);
                        $folder = FileUtilities::getParentFolder($path);
                        $zip = new ZipArchive();
                        if (true === $zip->open($tmpName)) {
                            $this->fileRestHandler->extractZipFile($folder, $zip, $clean);
                        }
                        else {
                            throw new Exception('Error opening temporary zip file.');
                        }
                    }
                    else {
                        $this->fileRestHandler->writeFile($path, $content, false, true);
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to create file $path.\n{$ex->getMessage()}");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $path)));
            }
            elseif (isset($_SERVER['HTTP_X_FOLDER_NAME']) && !empty($_SERVER['HTTP_X_FOLDER_NAME'])) {
                $x_folder_name = $_SERVER['HTTP_X_FOLDER_NAME'];
                if (0 !== strcasecmp($name, $x_folder_name)) {
                    throw new Exception("Header folder name '$x_folder_name' mismatched with REST resource '$name'.");
                }
                try {
                    $content = Utilities::getPostDataAsArray();
                    $this->fileRestHandler->createFolder($path, true, $content);
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to create file $path.\n{$ex->getMessage()}");
                }
                $result = array('folder' => array(array('name' => $name, 'path' => $path)));
            }
            elseif (isset($_FILES['files']) && !empty($_FILES['files'])) {
                // older html multipart/form-data post, should be single file
                $files = $_FILES['files'];
                //$files = Utilities::reorgFilePostArray($files);
                if (1 < count($files['error'])) {
                    throw new Exception("Multiple files uploaded to a single REST resource '$name'.");
                }
                $name = $files['name'][0];
                $fullPathName = $path . $name;
                $error = $files['error'][0];
                if (UPLOAD_ERR_OK == $error) {
                    $tmpName = $files["tmp_name"][0];
                    $contentType = $files['type'][0];
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    // create in permanent storage
                    try {
                        if (FileUtilities::isZipContent($contentType) && $extract) {
                            // need to extract zip file and move contents to storage
                            $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                            $zip = new ZipArchive();
                            if (true === $zip->open($tmpName)) {
                                $this->fileRestHandler->extractZipFile($path, $zip, $clean);
                            }
                            else {
                                throw new Exception('Error opening temporary zip file.');
                            }
                        }
                        else {
                            $this->fileRestHandler->moveFile($fullPathName, $tmpName, true);
                        }
                    }
                    catch (Exception $ex) {
                        throw new Exception("Failed to create file $fullPathName.\n{$ex->getMessage()}");
                    }
                }
                else {
                    throw new Exception("Failed to create file $fullPathName.\n$error");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            else {
                // possibly xml or json post either of file or folder to create, copy or move
                try {
                    $data = Utilities::getPostDataAsArray();
                    error_log(print_r($data, true));
                    //$this->addFiles($path, $data['files']);
                    $result = array();
                }
                catch (Exception $ex) {

                }
            }
        }

        return $result;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function actionPut()
    {
        $this->checkPermission('update');
        $path = Utilities::getArrayValue('resource', $_GET, '');
        $path_array = (!empty($path)) ? explode('/', $path) : array();
        $result = array();
        if (empty($path) || empty($path_array[count($path_array) - 1])) {
            // if ending in '/' then create files or folders in the directory
            if (isset($_SERVER['HTTP_X_FILE_NAME']) && !empty($_SERVER['HTTP_X_FILE_NAME'])) {
                // html5 single posting for file create
                $name = $_SERVER['HTTP_X_FILE_NAME'];
                $fullPathName = $path . $name;
                try {
                    $content = Utilities::getPostData();
                    if (empty($content)) {
                        // empty post?
                        error_log("Empty content in update file $fullPathName.");
                    }
                    $contentType = (isset($_SERVER['CONTENT_TYPE'])) ? $_SERVER['CONTENT_TYPE'] : '';
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    if (FileUtilities::isZipContent($contentType) && $extract) {
                        // need to extract zip file and move contents to storage
                        $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                        $tmpName = $tempDir  . $name;
                        file_put_contents($tmpName, $content);
                        $zip = new ZipArchive();
                        if (true === $zip->open($tmpName)) {
                            $this->fileRestHandler->extractZipFile($path, $zip, $clean);
                        }
                        else {
                            throw new Exception('Error opening temporary zip file.');
                        }
                    }
                    else {
                        $this->fileRestHandler->writeFile($fullPathName, $content, false);
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update file $fullPathName.\n{$ex->getMessage()}");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            elseif (isset($_SERVER['HTTP_X_FOLDER_NAME']) && !empty($_SERVER['HTTP_X_FOLDER_NAME'])) {
                // html5 single posting for folder create
                $name = $_SERVER['HTTP_X_FOLDER_NAME'];
                $fullPathName = $path . $name;
                try {
                    $content = Utilities::getPostDataAsArray();
                    $this->fileRestHandler->updateFolderProperties($fullPathName, $content);
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update folder $fullPathName.\n{$ex->getMessage()}");
                }
                $result = array('folder' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            elseif (isset($_FILES['files']) && !empty($_FILES['files'])) {
                // older html multi-part/form-data post, single or multiple files
                $files = $_FILES['files'];
                $out = array();
                foreach ($files["error"] as $key => $error) {
                    $name = $files["name"][$key];
                    $fullPathName = $path . $name;
                    $out[$key] = array('name' => $name, 'path' => $fullPathName);
                    if ($error == UPLOAD_ERR_OK) {
                        $tmp_name = $files["tmp_name"][$key];
                        //$content_type = $files['type'][$key];

                        // create in permanent storage
                        try {
                            $this->fileRestHandler->moveFile($fullPathName, $tmp_name, false);
                        }
                        catch (Exception $ex) {
                            $out[$key]['error'] = array('code' => $ex->getCode(),
                                                        'message' => "Failed to create file $fullPathName.\n{$ex->getMessage()}");
                        }
                    }
                    else {
                        $out[$key]['error'] = array('code' => 500,
                                                    'message' => "Failed to create file $fullPathName.\n$error");
                    }
                }
                $result = array('file' => $out);
            }
            else {
                // possibly xml or json post either of files or folders to create, copy or move
                try {
                    $content = Utilities::getPostDataAsArray();
                    if (empty($content)) {
                        // create folder from resource path
                        $this->fileRestHandler->createFolder($path);
                        $result = array('folder' => array(array('path' => $path)));
                    }
                    else {
                        $out = array('folder' => array(), 'file' => array());
                        $folders = Utilities::getArrayValue('folder', $content, null);
                        if (empty($folders)) {
                            $folders = (isset($content['folders']['folder']) ? $content['folders']['folder'] : null);
                        }
                        if (!empty($folders)) {
                            if (!isset($folders[0])) {
                                // single folder, make into array
                                $folders = array($folders);
                            }
                            foreach ($folders as $key=>$folder) {
                                if (isset($folder['source_path'])) {
                                    // copy or move
                                    $srcPath = $folder['source_path'];
                                    $name = FileUtilities::getNameFromPath($srcPath);
                                    $fullPathName = $path . $name . '/';
                                    $out['folder'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    try {
                                        $this->fileRestHandler->copyFolder($fullPathName, $srcPath, true);
                                        $deleteSource = (isset($folder['delete_source'])) ? Utilities::boolval($folder['delete_source']) : false;
                                        if ($deleteSource) {
                                            $this->fileRestHandler->deleteFolder($srcPath, true);
                                        }
                                    }
                                    catch (Exception $ex) {
                                        $out['folder'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                                elseif (isset($folder['content'])) {
                                    $name = $folder['name'];
                                    $fullPathName = $path . $name;
                                    $out['folder'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    $content = $folder['content'];
                                    $isBase64 = (isset($folder['is_base64'])) ? Utilities::boolval($folder['is_base64']) : false;
                                    if ($isBase64) {
                                        $content = base64_decode($content);
                                    }
                                    try {
                                        $this->fileRestHandler->createFolder($fullPathName, true, $content);
                                    }
                                    catch (Exception $ex) {
                                        $out['folder'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                            }
                        }
                        $files = Utilities::getArrayValue('file', $content, null);
                        if (empty($files)) {
                            $files = (isset($content['files']['file']) ? $content['files']['file'] : null);
                        }
                        if (!empty($files)) {
                            if (!isset($files[0])) {
                                // single file, make into array
                                $files = array($files);
                            }
                            foreach ($files as $key=>$file) {
                                if (isset($file['source_path'])) {
                                    // copy or move
                                    $srcPath = $file['source_path'];
                                    $name = FileUtilities::getNameFromPath($srcPath);
                                    $fullPathName = $path . $name;
                                    $out['file'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    try {
                                        $this->fileRestHandler->copyFile($fullPathName, $srcPath, true);
                                        $deleteSource = (isset($file['delete_source'])) ? Utilities::boolval($file['delete_source']) : false;
                                        if ($deleteSource) {
                                            $this->fileRestHandler->deleteFile($srcPath);
                                        }
                                    }
                                    catch (Exception $ex) {
                                        $out['file'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                                elseif (isset($file['content'])) {
                                    $name = $file['name'];
                                    $fullPathName = $path . $name;
                                    $out['file'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    $content = $file['content'];
                                    $isBase64 = (isset($file['is_base64'])) ? Utilities::boolval($file['is_base64']) : false;
                                    if ($isBase64) {
                                        $content = base64_decode($content);
                                    }
                                    try {
                                        $this->fileRestHandler->writeFile($fullPathName, $content);
                                    }
                                    catch (Exception $ex) {
                                        $out['file'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                            }
                        }
                        $result = $out;
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update folders or files from request.\n{$ex->getMessage()}");
                }
            }
        }
        else {
            // if ending in file name, create the file or folder
            $name = substr($path, strripos($path, '/') + 1);
            if (isset($_SERVER['HTTP_X_FILE_NAME']) && !empty($_SERVER['HTTP_X_FILE_NAME'])) {
                $x_file_name = $_SERVER['HTTP_X_FILE_NAME'];
                if (0 !== strcasecmp($name, $x_file_name)) {
                    throw new Exception("Header file name '$x_file_name' mismatched with REST resource '$name'.");
                }
                try {
                    $content = Utilities::getPostData();
                    $contentType = (isset($_SERVER['CONTENT_TYPE'])) ? $_SERVER['CONTENT_TYPE'] : '';
                    if (empty($content)) {
                        // empty post?
                        error_log("Empty content in write file $path to storage.");
                    }
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    if (FileUtilities::isZipContent($contentType) && $extract) {
                        // need to extract zip file and move contents to storage
                        $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                        $tmpName = $tempDir  . $name;
                        file_put_contents($tmpName, $content);
                        $folder = FileUtilities::getParentFolder($path);
                        $zip = new ZipArchive();
                        if (true === $zip->open($tmpName)) {
                            $this->fileRestHandler->extractZipFile($folder, $zip, $clean);
                        }
                        else {
                            throw new Exception('Error opening temporary zip file.');
                        }
                    }
                    else {
                        $this->fileRestHandler->writeFile($path, $content, false);
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update file $path.\n{$ex->getMessage()}");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $path)));
            }
            elseif (isset($_SERVER['HTTP_X_FOLDER_NAME']) && !empty($_SERVER['HTTP_X_FOLDER_NAME'])) {
                $x_folder_name = $_SERVER['HTTP_X_FOLDER_NAME'];
                if (0 !== strcasecmp($name, $x_folder_name)) {
                    throw new Exception("Header folder name '$x_folder_name' mismatched with REST resource '$name'.");
                }
                try {
                    $content = Utilities::getPostDataAsArray();
                    $this->fileRestHandler->updateFolderProperties($path, $content);
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update folder $path.\n{$ex->getMessage()}");
                }
                $result = array('folder' => array(array('name' => $name, 'path' => $path)));
            }
            elseif (isset($_FILES['files']) && !empty($_FILES['files'])) {
                // older html multipart/form-data post, should be single file
                $files = $_FILES['files'];
                //$files = Utilities::reorgFilePostArray($files);
                if (1 < count($files['error'])) {
                    throw new Exception("Multiple files uploaded to a single REST resource '$name'.");
                }
                $name = $files['name'][0];
                $fullPathName = $path . $name;
                $error = $files['error'][0];
                if (UPLOAD_ERR_OK == $error) {
                    $tmpName = $files["tmp_name"][0];
                    $contentType = $files['type'][0];
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    // create in permanent storage
                    try {
                        if (FileUtilities::isZipContent($contentType) && $extract) {
                            // need to extract zip file and move contents to storage
                            $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                            $zip = new ZipArchive();
                            if (true === $zip->open($tmpName)) {
                                $this->fileRestHandler->extractZipFile($path, $zip, $clean);
                            }
                            else {
                                throw new Exception('Error opening temporary zip file.');
                            }
                        }
                        else {
                            $this->fileRestHandler->moveFile($fullPathName, $tmpName, true);
                        }
                    }
                    catch (Exception $ex) {
                        throw new Exception("Failed to create file $fullPathName.\n{$ex->getMessage()}");
                    }
                }
                else {
                    throw new Exception("Failed to create file $fullPathName.\n$error");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            else {
                // possibly xml or json post either of file or folder to create, copy or move
                try {
                    $data = Utilities::getPostDataAsArray();
                    error_log(print_r($data, true));
                    //$this->addFiles($path, $data['files']);
                    $result = array();
                }
                catch (Exception $ex) {

                }
            }
        }

        return $result;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function actionMerge()
    {
        $this->checkPermission('update');
        $path = Utilities::getArrayValue('resource', $_GET, '');
        $path_array = (!empty($path)) ? explode('/', $path) : array();
        $result = array();
        if (empty($path) || empty($path_array[count($path_array) - 1])) {
            // if ending in '/' then create files or folders in the directory
            if (isset($_SERVER['HTTP_X_FILE_NAME']) && !empty($_SERVER['HTTP_X_FILE_NAME'])) {
                // html5 single posting for file create
                $name = $_SERVER['HTTP_X_FILE_NAME'];
                $fullPathName = $path . $name;
                try {
                    $content = Utilities::getPostData();
                    if (empty($content)) {
                        // empty post?
                        error_log("Empty content in update file $fullPathName.");
                    }
                    $contentType = (isset($_SERVER['CONTENT_TYPE'])) ? $_SERVER['CONTENT_TYPE'] : '';
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    if (FileUtilities::isZipContent($contentType) && $extract) {
                        // need to extract zip file and move contents to storage
                        $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                        $tmpName = $tempDir  . $name;
                        file_put_contents($tmpName, $content);
                        $zip = new ZipArchive();
                        if (true === $zip->open($tmpName)) {
                            $this->fileRestHandler->extractZipFile($path, $zip, $clean);
                        }
                        else {
                            throw new Exception('Error opening temporary zip file.');
                        }
                    }
                    else {
                        $this->fileRestHandler->writeFile($fullPathName, $content, false);
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update file $fullPathName.\n{$ex->getMessage()}");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            elseif (isset($_SERVER['HTTP_X_FOLDER_NAME']) && !empty($_SERVER['HTTP_X_FOLDER_NAME'])) {
                // html5 single posting for folder create
                $name = $_SERVER['HTTP_X_FOLDER_NAME'];
                $fullPathName = $path . $name;
                try {
                    $content = Utilities::getPostDataAsArray();
                    $this->fileRestHandler->updateFolderProperties($fullPathName, $content);
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update folder $fullPathName.\n{$ex->getMessage()}");
                }
                $result = array('folder' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            elseif (isset($_FILES['files']) && !empty($_FILES['files'])) {
                // older html multi-part/form-data post, single or multiple files
                $files = $_FILES['files'];
                $out = array();
                foreach ($files["error"] as $key => $error) {
                    $name = $files["name"][$key];
                    $fullPathName = $path . $name;
                    $out[$key] = array('name' => $name, 'path' => $fullPathName);
                    if ($error == UPLOAD_ERR_OK) {
                        $tmp_name = $files["tmp_name"][$key];
                        //$content_type = $files['type'][$key];

                        // create in permanent storage
                        try {
                            $this->fileRestHandler->moveFile($fullPathName, $tmp_name, false);
                        }
                        catch (Exception $ex) {
                            $out[$key]['error'] = array('code' => $ex->getCode(),
                                                        'message' => "Failed to create file $fullPathName.\n{$ex->getMessage()}");
                        }
                    }
                    else {
                        $out[$key]['error'] = array('code' => 500,
                                                    'message' => "Failed to create file $fullPathName.\n$error");
                    }
                }
                $result = array('file' => $out);
            }
            else {
                // possibly xml or json post either of files or folders to create, copy or move
                try {
                    $content = Utilities::getPostDataAsArray();
                    if (empty($content)) {
                        // create folder from resource path
                        $this->fileRestHandler->createFolder($path);
                        $result = array('folder' => array(array('path' => $path)));
                    }
                    else {
                        $out = array('folder' => array(), 'file' => array());
                        $folders = Utilities::getArrayValue('folder', $content, null);
                        if (empty($folders)) {
                            $folders = (isset($content['folders']['folder']) ? $content['folders']['folder'] : null);
                        }
                        if (!empty($folders)) {
                            if (!isset($folders[0])) {
                                // single folder, make into array
                                $folders = array($folders);
                            }
                            foreach ($folders as $key=>$folder) {
                                if (isset($folder['source_path'])) {
                                    // copy or move
                                    $srcPath = $folder['source_path'];
                                    $name = FileUtilities::getNameFromPath($srcPath);
                                    $fullPathName = $path . $name . '/';
                                    $out['folder'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    try {
                                        $this->fileRestHandler->copyFolder($fullPathName, $srcPath, true);
                                        $deleteSource = (isset($folder['delete_source'])) ? Utilities::boolval($folder['delete_source']) : false;
                                        if ($deleteSource) {
                                            $this->fileRestHandler->deleteFolder($srcPath, true);
                                        }
                                    }
                                    catch (Exception $ex) {
                                        $out['folder'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                                elseif (isset($folder['content'])) {
                                    $name = $folder['name'];
                                    $fullPathName = $path . $name;
                                    $out['folder'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    $content = $folder['content'];
                                    $isBase64 = (isset($folder['is_base64'])) ? Utilities::boolval($folder['is_base64']) : false;
                                    if ($isBase64) {
                                        $content = base64_decode($content);
                                    }
                                    try {
                                        $this->fileRestHandler->createFolder($fullPathName, true, $content);
                                    }
                                    catch (Exception $ex) {
                                        $out['folder'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                            }
                        }
                        $files = Utilities::getArrayValue('file', $content, null);
                        if (empty($files)) {
                            $files = (isset($content['files']['file']) ? $content['files']['file'] : null);
                        }
                        if (!empty($files)) {
                            if (!isset($files[0])) {
                                // single file, make into array
                                $files = array($files);
                            }
                            foreach ($files as $key=>$file) {
                                if (isset($file['source_path'])) {
                                    // copy or move
                                    $srcPath = $file['source_path'];
                                    $name = FileUtilities::getNameFromPath($srcPath);
                                    $fullPathName = $path . $name;
                                    $out['file'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    try {
                                        $this->fileRestHandler->copyFile($fullPathName, $srcPath, true);
                                        $deleteSource = (isset($file['delete_source'])) ? Utilities::boolval($file['delete_source']) : false;
                                        if ($deleteSource) {
                                            $this->fileRestHandler->deleteFile($srcPath);
                                        }
                                    }
                                    catch (Exception $ex) {
                                        $out['file'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                                elseif (isset($file['content'])) {
                                    $name = $file['name'];
                                    $fullPathName = $path . $name;
                                    $out['file'][$key] = array('name' => $name, 'path' => $fullPathName);
                                    $content = $file['content'];
                                    $isBase64 = (isset($file['is_base64'])) ? Utilities::boolval($file['is_base64']) : false;
                                    if ($isBase64) {
                                        $content = base64_decode($content);
                                    }
                                    try {
                                        $this->fileRestHandler->writeFile($fullPathName, $content);
                                    }
                                    catch (Exception $ex) {
                                        $out['file'][$key]['error'] = array('message' => $ex->getMessage());
                                    }
                                }
                            }
                        }
                        $result = $out;
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update folders or files from request.\n{$ex->getMessage()}");
                }
            }
        }
        else {
            // if ending in file name, create the file or folder
            $name = substr($path, strripos($path, '/') + 1);
            if (isset($_SERVER['HTTP_X_FILE_NAME']) && !empty($_SERVER['HTTP_X_FILE_NAME'])) {
                $x_file_name = $_SERVER['HTTP_X_FILE_NAME'];
                if (0 !== strcasecmp($name, $x_file_name)) {
                    throw new Exception("Header file name '$x_file_name' mismatched with REST resource '$name'.");
                }
                try {
                    $content = Utilities::getPostData();
                    $contentType = (isset($_SERVER['CONTENT_TYPE'])) ? $_SERVER['CONTENT_TYPE'] : '';
                    if (empty($content)) {
                        // empty post?
                        error_log("Empty content in write file $path to storage.");
                    }
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    if (FileUtilities::isZipContent($contentType) && $extract) {
                        // need to extract zip file and move contents to storage
                        $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                        $tmpName = $tempDir  . $name;
                        file_put_contents($tmpName, $content);
                        $folder = FileUtilities::getParentFolder($path);
                        $zip = new ZipArchive();
                        if (true === $zip->open($tmpName)) {
                            $this->fileRestHandler->extractZipFile($folder, $zip, $clean);
                        }
                        else {
                            throw new Exception('Error opening temporary zip file.');
                        }
                    }
                    else {
                        $this->fileRestHandler->writeFile($path, $content, false);
                    }
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update file $path.\n{$ex->getMessage()}");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $path)));
            }
            elseif (isset($_SERVER['HTTP_X_FOLDER_NAME']) && !empty($_SERVER['HTTP_X_FOLDER_NAME'])) {
                $x_folder_name = $_SERVER['HTTP_X_FOLDER_NAME'];
                if (0 !== strcasecmp($name, $x_folder_name)) {
                    throw new Exception("Header folder name '$x_folder_name' mismatched with REST resource '$name'.");
                }
                try {
                    $content = Utilities::getPostDataAsArray();
                    $this->fileRestHandler->updateFolderProperties($path, $content);
                }
                catch (Exception $ex) {
                    throw new Exception("Failed to update folder $path.\n{$ex->getMessage()}");
                }
                $result = array('folder' => array(array('name' => $name, 'path' => $path)));
            }
            elseif (isset($_FILES['files']) && !empty($_FILES['files'])) {
                // older html multipart/form-data post, should be single file
                $files = $_FILES['files'];
                //$files = Utilities::reorgFilePostArray($files);
                if (1 < count($files['error'])) {
                    throw new Exception("Multiple files uploaded to a single REST resource '$name'.");
                }
                $name = $files['name'][0];
                $fullPathName = $path . $name;
                $error = $files['error'][0];
                if (UPLOAD_ERR_OK == $error) {
                    $tmpName = $files["tmp_name"][0];
                    $contentType = $files['type'][0];
                    $extract = Utilities::boolval(Utilities::getArrayValue('extract', $_REQUEST, false));
                    // create in permanent storage
                    try {
                        if (FileUtilities::isZipContent($contentType) && $extract) {
                            // need to extract zip file and move contents to storage
                            $clean = Utilities::boolval(Utilities::getArrayValue('clean', $_REQUEST, false));
                            $zip = new ZipArchive();
                            if (true === $zip->open($tmpName)) {
                                $this->fileRestHandler->extractZipFile($path, $zip, $clean);
                            }
                            else {
                                throw new Exception('Error opening temporary zip file.');
                            }
                        }
                        else {
                            $this->fileRestHandler->moveFile($fullPathName, $tmpName, true);
                        }
                    }
                    catch (Exception $ex) {
                        throw new Exception("Failed to create file $fullPathName.\n{$ex->getMessage()}");
                    }
                }
                else {
                    throw new Exception("Failed to create file $fullPathName.\n$error");
                }
                $result = array('file' => array(array('name' => $name, 'path' => $fullPathName)));
            }
            else {
                // possibly xml or json post either of file or folder to create, copy or move
                try {
                    $data = Utilities::getPostDataAsArray();
                    error_log(print_r($data, true));
                    //$this->addFiles($path, $data['files']);
                    $result = array();
                }
                catch (Exception $ex) {

                }
            }
        }

        return $result;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function actionDelete()
    {
        $this->checkPermission('delete');
        $path = Utilities::getArrayValue('resource', $_GET, '');
        $path_array = (!empty($path)) ? explode('/', $path) : array();
        if (empty($path)) {
            // multi-file or folder delete via post data
            try {
                $content = Utilities::getPostDataAsArray();
                if (empty($content)) {
                    throw new Exception("Empty file or folder path given for storage delete.");
                }
                else {
                    $out = array();
                    if (isset($content['file'])) {
                        $files = $content['file'];
                        $out['file'] = $this->fileRestHandler->deleteFiles($files);
                    }
                    if (isset($content['folder'])) {
                        $folders = $content['folder'];
                        $out['folder'] = $this->fileRestHandler->deleteFolders($folders);
                    }
                    $result = $out;
                }
            }
            catch (Exception $ex) {
                throw new Exception("Failed to delete storage folders.\n{$ex->getMessage()}");
            }
        }
        elseif (empty($path_array[count($path_array) - 1])) {
            // delete directory of files and the directory itself
            $force = Utilities::boolval(Utilities::getArrayValue('force', $_REQUEST, false));
            try {
                $this->fileRestHandler->deleteFolder($path, $force);
            }
            catch (Exception $ex) {
                throw new Exception("Failed to delete storage folder '$path'.\n{$ex->getMessage()}");
            }
            $result = array('folder' => array(array('path' => $path)));
        }
        else {
            // delete file from permanent storage
            try {
                $this->fileRestHandler->deleteFile($path);
            }
            catch (Exception $ex) {
                throw new Exception("Failed to delete storage file '$path'.\n{$ex->getMessage()}");
            }
            $result = array('file' => array(array('path' => $path)));
        }

        return $result;
    }
}
