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

class JWSDK_Package_Manager
{
	private $globalConfig;         // JWSDK_GlobalConfig
	private $resourceManager;      // JWSDK_Resource_Manager
	private $packageMap = array(); // Map from name:String to JWSDK_Package
	
	public function __construct(
		$globalConfig,    // JWSDK_GlobalConfig
		$resourceManager) // JWSDK_Resource_Manager
	{
		$this->globalConfig = $globalConfig;
		$this->resourceManager = $resourceManager;
	}
	
	public function readPackage( // JWSDK_Package
		$name) // String
	{
		$package = $this->getPackage($name);
		if ($package)
			return $package;
		
		$path = $this->globalConfig->getPackagePath($name);
		
		$contents = @file_get_contents($path);
		if ($contents === false)
			throw new Exception("Package doesn't exist (name: $name)");
		
		$contents = JWSDK_Util_String::removeComments($contents);
		
		$scripts = explode("\n", JWSDK_Util_String::smoothCrlf($contents));
		$scripts = self::removeEmptyStrings($scripts);
		
		$package = new JWSDK_Package($name);
		for ($i = 0; $i < count($scripts); ++$i)
		{
			$resource = $this->resourceManager->getResourceByDefinition($scripts[$i]);
			$resource = $this->resourceManager->convertResource($resource);
			
			$package->addResource($resource);
		}
		
		$this->addPackage($package);
		
		return $package;
	}
	
	public function compressPackage( // JWSDK_Resource, compressed file
		$package) // JWSDK_Package
	{
		$compressedResource = $package->getCompressedResource();
		if ($compressedResource)
			return $compressedResource;
		
		$name = $package->getName();
		
		$mergePath = $this->globalConfig->getPackageMergePath($name);
		$buildPath = $this->globalConfig->getPackageBuildPath($name);
		
		if (!JWSDK_Util_File::write($mergePath, $this->getPackageMergedContents($package)))
			throw new Exception("Can't create merged js file (name: $name)");
		
		if (!JWSDK_Util_File::mkdir_recursive($buildPath))
			throw new Exception("Can't create directory for output js file (name: $name)");
		
		if (!JWSDK_Util_File::compress($mergePath, $buildPath))
			throw new Exception("Error while running YUI Compressor (name: $name, input: $mergePath, output: $buildPath). See signin/build/yui.log for details");
		
		$compressedResource = new JWSDK_Resource($this->globalConfig->getPackageBuildUrl($name), 'js');
		$package->setCompressedResource($compressedResource);
		
		return $compressedResource;
	}
	
	private function getPackageMergedContents( // String
		$package) // JWSDK_Package
	{
		$buf = array()
		foreach ($package->getResources() as $resource)
			$buf[] = $this->resourceManager->getResourceContents($resource);
		
		return implode("\n", $buf);
	}
	
	private function addPackage(
		$package) // JWSDK_Package
	{
		$this->packages[$package->getName()] = $package;
	}
	
	private function getPackage( // JWSDK_Package
		$name) // String
	{
		return JWSDK_Util_Array::get($this->packages, $name);
	}
}