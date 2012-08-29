<?php namespace AssetCompressor;

use Laravel\HTML;
use Laravel\Config;
use Laravel\File;
use Laravel\Asset_Container as Laravel_Asset_Container;
use Laravel\Asset as Laravel_Asset;

use AssetCompressor\Drivers\MinifyCSS\Compressor;
use AssetCompressor\Drivers\MinifyJS\JSCompress;

class Asset_Container extends Laravel_Asset_Container {

	private $compress = false;
	private $group = '';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->config = Config::get('assetcompressor::assetcompressor');
		$this->config['cache_dir'] = $this->config['cache_dir'] . '/';
		$this->config['cache_dir_path'] = path('public').$this->config['cache_dir'];

		if( ! is_dir($this->config['cache_dir_path']))
			mkdir($this->config['cache_dir_path']);
	}

	/**
	 * Override parent class.
	 */
	public function styles()
	{
		// reset the compression in case of multiple asset calls
		$this->compress = false;

		$this->group = 'style';
		return $this;
	}

	/**
	 * Override parent class.
	 */
	public function scripts()
	{
		// reset the compression in case of multiple asset calls
		$this->compress = false;

		$this->group = 'script';
		return $this;
	}

	public function compress($compress = true)
	{
		$this->compress = $compress;
		return $this;
	}

	public function get()
	{
		return $this->group($this->group);
	}

	/**
	 * Return one tag with the minified scripts or styles
	 */
	protected function group($group)
	{
		if ( ! isset($this->assets[$group]) or count($this->assets[$group]) == 0) return '';

		if ($this->compress)
		{
			return $this->minify($group, $this->arrange($this->assets[$group]));
		}

		$assets = '';

		foreach ($this->arrange($this->assets[$group]) as $name => $data)
		{
			$assets .= $this->asset($group, $name);
		}

		return $assets;
	}

	public function minify($group, $assets)
	{
		$combined_assets = array();
		foreach($assets as $name => $data)
		{
			asort($data['attributes']);
			$attributes_string = json_encode($data['attributes']);
			$combined_assets[$attributes_string][$name] = $data;
		}

		$assets_html = '';
		$compile = false;
		foreach ($combined_assets as $attributes_string => $assets)
		{
			$files_to_compile = array();
			$output_files = array();
			foreach($assets as $name => $data)
			{
				$file = path('public') . $data['source'];
				if( ! File::exists($file))
				{
					throw new Exception('The Asset you are trying to compress does not exist ('.$file.')');
				}

				$output_files[] = $name . '.' . ($group == 'script' ? 'js' : 'css');
				$files_to_compile[] = $data['source'];
			}

			if( ! count($output_files))
			{
				return;
			}

			$output_file_name = substr(md5(implode(',', $output_files)), 0, 16) . '.' . ($group == 'script' ? 'js' : 'css');

			if( ! file_exists($this->config['cache_dir_path'] . $output_file_name))
			{
				$compile = true;
			}
			else
			{
				foreach ($files_to_compile as $file)
				{
					if(File::modified($this->config['cache_dir_path'] . $output_file_name) < File::modified(path('public') . $file))
					{
						$compile = true;
						break;
					}
				}
			}

			$method = 'minify_'.$group;

			$assets_html .= $this->$method($assets, $data['attributes'], $files_to_compile, $output_file_name, $compile);
		}

		return $assets_html;
	}

	protected function minify_style($assets, $attributes, $files_to_compile, $output_file_name, $compile)
	{
		if ($compile)
		{
			// Group all the assets first
			$concat_files = '';
			foreach ($files_to_compile as $file)
			{
				$concat_files .= File::get(path('public') . $file);
			}

			// Compress the concatenated assets
			$output_file_contents = Compressor::process($concat_files);

			// Write to the cache
			File::put($this->config['cache_dir_path'] . $output_file_name, $output_file_contents);
		}

		return HTML::style($this->config['cache_dir'] . $output_file_name, $attributes);
	}

	protected function minify_script($assets, $attributes, $files_to_compile, $output_file_name, $compile)
	{
		if ($compile)
		{
			$compressor = new JSCompress();

			foreach($files_to_compile as $file)
			{
				$compressor->add(path('public') . $file);
			}

			// Compress the assets
			$output_file_contents = $compressor->compress();

			// Write to the cache
			File::put($this->config['cache_dir_path'] . $output_file_name, $output_file_contents);
		}

		return HTML::script($this->config['cache_dir'] . $output_file_name, $attributes);
	}

}

class Asset extends Laravel_Asset {

	/**
	 * Let it grab the extended Asset_Container class
	 */
	public static function container($container = 'default')
	{
		if ( ! isset(static::$containers[$container]))
		{
			static::$containers[$container] = new Asset_Container($container);
		}

		return static::$containers[$container];
	}

}
