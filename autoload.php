<?php
namespace Nest;

function autoLoad($className)
{
    $className = ltrim($className, '\\');
    $fileName  = '';
    $namespace = '';
    if ($lastNsPos = strrpos($className, '\\')) {
        $namespace = substr($className, 0, $lastNsPos);
        $className = substr($className, $lastNsPos + 1);
        $fileName  = DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
    }
    $fileName .= str_replace('_', DIRECTORY_SEPARATOR, $className) . '.php';
    $fileName = __DIR__ . DIRECTORY_SEPARATOR . $fileName;
    if(file_exists($fileName))
    {
        require($fileName);
    }
}

spl_autoload_register(__NAMESPACE__.'\autoload');

?>