<?php

/*
	jWidget SDK source file.
	
	Copyright (C) 2012 Egor Nepomnyaschih
	
	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU Lesser General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Lesser General Public License for more details.
	
	You should have received a copy of the GNU Lesser General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

class JWSDK_Resource_Manager
{
	private $globalConfig;         // JWSDK_GlobalConfig
	private $fileManager;          // JWSDK_FileManager
	
	private $attachers = array();  // Map from type:String to JWSDK_Resource_Attacher
	private $converters = array(); // Map from type:String to JWSDK_Resource_Converter
	private $resources = array();  // Map from name:String to JWSDK_Resource
	
	public function __construct(
		$globalConfig, // JWSDK_GlobalConfig
		$fileManager)  // JWSDK_FileManager
	{
		$this->globalConfig = $globalConfig;
		$this->fileManager = $fileManager;
		
		$this->registerConverter(new JWSDK_Resource_Converter_Css());
		$this->registerConverter(new JWSDK_Resource_Converter_JwHtml());
		$this->registerConverter(new JWSDK_Resource_Converter_Txt());
		$this->registerConverter(new JWSDK_Resource_Converter_Html());
		$this->registerConverter(new JWSDK_Resource_Converter_Json());
		$this->registerConverter(new JWSDK_Resource_Converter_Js());
	}
	
	public function getResourceByDefinition( // JWSDK_Resource
		$definition) // *
	{
		try
		{
			if (is_string($definition))
				return $this->getResourceByString($definition);
			
			if (is_array($definition))
				return $this->getResourceByJson($definition);
			
			throw new JWSDK_Exception_InvalidResourceFormat();
		}
		catch (JWSDK_Exception $e)
		{
			throw new JWSDK_Exception_ResourceReadError(json_encode($definition), $e);
		}
	}
	
	public function convertResource( // JWSDK_File
		$resource) // JWSDK_Resource
	{
		$file = $resource->getOutputFile();
		if ($file)
			return $file;
		
		$file = $this->getFileByResource($resource);
		$resource->setOutputFile($file);
		
		return $file;
	}
	
	public function getResourceContents( // String
		$resource) // JWSDK_Resource
	{
		$name = $resource->getName();
		$sourcePath = $this->getResourceSourcePath($name);
		return JWSDK_Util_File::read($sourcePath, 'resource file');
	}
	
	private function getResourceByString( // JWSDK_Resource
		$str) // String
	{
		$tokens = explode(":", $str);
		$name = trim($tokens[0]);
		
		$resource = $this->getResource($name);
		if ($resource)
			throw new JWSDK_Exception_DuplicatedResourceError($name);
		
		$converter = $this->getConverterByResourceName($name);
		if (!$converter)
			throw new JWSDK_Exception_InvalidResourceType();
		
		if (count($tokens) == 1)
		{
			$params = array();
		}
		else
		{
			$params = explode(",", $tokens[1]);
			for ($i = 0; $i < count($params); $i++)
				$params[$i] = trim($params[$i]);
		}
		
		return $this->createResource($name, $converter->getType(), $converter->getAttacher(), $converter->getParamsByArray($params));
	}
	
	private function getResourceByJson( // JWSDK_Resource
		$json) // Object
	{
		if (!isset($json['path']) || !is_string($json['path']))
			throw new JWSDK_Exception_InvalidResourceFormat();
		
		$name = $json['path'];
		
		$resource = $this->getResource($name);
		if ($resource)
			throw new JWSDK_Exception_DuplicatedResourceError($name);
		
		if (isset($json['type']) && is_string($json['type']))
			$converter = $this->getConverter($json['type']);
		else
			$converter = $this->getConverterByResourceName($name);
		
		if (!$converter)
			throw new JWSDK_Exception_InvalidResourceType();
		
		return $this->createResource($name, $converter->getType(), $converter->getAttacher(), $converter->getParamsByJson($json));
	}
	
	private function getFileByResource( // JWSDK_File
		$resource) // JWSDK_Resource
	{
		$name = $resource->getName();
		$type = $resource->getType();
		
		try
		{
			$converter = $this->getConverter($type);
			if (!$converter->isConvertion())
				return $this->fileManager->getFile($resource->getName(), $converter->getAttacher());
			
			JWSDK_Log::logTo('build.log', "Converting resource $name");
			
			$sourceContents = $this->getResourceContents($resource);
			$buildContents = $converter->convertResource($name, $sourceContents, $resource->getParams());
			
			$buildName = $this->getResourceBuildName($name);
			$buildPath = $this->getResourceBuildPath($name);
			
			JWSDK_Util_File::write($buildPath, $buildContents);
			
			return $this->fileManager->getFile($buildName, $converter->getAttacher());
		}
		catch (JWSDK_Exception $e)
		{
			throw new JWSDK_Exception_ResourceConvertionError($name, $type, $e);
		}
	}
	
	private function getResourceSourcePath( // String
		$name) // String
	{
		return $this->globalConfig->getPublicPath() . "/$name";
	}
	
	private function getResourceBuildName( // String
		$name) // String
	{
		return $this->globalConfig->getBuildUrl() . "/$name.js";
	}
	
	private function getResourceBuildPath( // String
		$name) // String
	{
		return $this->globalConfig->getPublicPath() . "/" . $this->getResourceBuildName($name);
	}
	
	private function registerConverter(
		$converter) // JWSDK_Resource_Converter
	{
		$this->converters[$converter->getType()] = $converter;
	}
	
	private function getConverter( // JWSDK_Resource_Converter
		$type) // String
	{
		return JWSDK_Util_Array::get($this->converters, $type);
	}
	
	private function getConverterByResourceName( // JWSDK_Resource_Converter
		$name) // String
	{
		foreach ($this->converters as $type => $converter)
		{
			if (preg_match("/\.$type$/i", $name))
				return $converter;
		}
		
		return null;
	}
	
	private function createResource( // JWSDK_Resource
		$name,     // String
		$type,     // String
		$attacher, // String
		$params)   // Array of String
	{
		return new JWSDK_Resource($name, $type, $params, $this->fileManager->getFile($name, $attacher));
	}
	
	private function registerResource(
		$resource) // JWSDK_Resource
	{
		$this->resources[$resource->getName()] = $resource;
	}
	
	private function getResource( // JWSDK_Resource
		$name) // String
	{
		return JWSDK_Util_Array::get($this->resources, $name);
	}
}
