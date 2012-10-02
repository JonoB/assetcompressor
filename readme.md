This is largely based on Vespakoen's assetcompressor, which is available at https://github.com/Vespakoen/AssetCompressor

There are a few main differences:

1 JS files are now compressed with the google closure API, and not the Java Jar file. I had a few issues where files were not compressed properly with the jar, possibly because they are out of date. Using the API means that we always have the most up-to-date compiler.

2 Assets need to be output as follows:

```php
Asset::styles()->compress()->get();
```
This has the major benefit in that the config file is no longer needed - you can decide if you want the files to be compressed at run time.

If you don't want compression, then you can do either of the following:
```php
// These both do the same thing
Asset::styles()->get();
Asset::styles()->compress(false)->get();
```

3 CSS assets are first combined and then minified. Previously they were minified individually and then combined afterwards. I'm not sure if there is actually any benefit to this, but it seemed more logical - especially if we want to allow users in the future to combine() and not compress() 

4 Query strings are no longer used to fingerprint cached files, as there are several disadvantages (see http://guides.rubyonrails.org/asset_pipeline.html). Cache filenames are now random strings (md5, limited to 16 chars).

5 JSCompressor has a lot of other options for debugging. I have not yet linked these up to the asset bundle, but they are there and are very useful for development.

6 It may make sense to allow users to choose their JS minification driver - either Closure API or Closure Java.