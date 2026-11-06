# Acknowledgements

Bicho for WordPress uses the Bicho PHP and JavaScript SDKs, both Apache-2.0:

- [Bicho PHP source](https://github.com/bicho-fyi/bicho-php), [license](https://github.com/bicho-fyi/bicho-php/blob/main/LICENSE), and [acknowledgements](https://github.com/bicho-fyi/bicho-php/blob/main/ACKNOWLEDGEMENTS.md).
- [Bicho JavaScript source](https://github.com/bicho-fyi/bicho-javascript), [license](https://github.com/bicho-fyi/bicho-javascript/blob/main/LICENSE), and [acknowledgements](https://github.com/bicho-fyi/bicho-javascript/blob/main/ACKNOWLEDGEMENTS.md).

The build retains both SDK license texts and acknowledgement files in dist/. PHP namespace scoping isolates the bundled SDK from other plugins; it does not change transport behavior.

WordPress is separate software under its own GPL license. Docker images, build tools, and test dependencies retain their own licenses and are not bundled in the installed plugin. WordPress and Cloudflare names belong to their respective owners; this plugin does not imply their sponsorship or endorsement.
