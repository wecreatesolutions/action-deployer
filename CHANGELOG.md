v1.1.20
* using php 7.4
* using google dns server

# v1.1.7
* added ScriptHandler SEF logic to deployer
* composer install, cache:clear, migration, init cache
* update deployer to version 6.8.0
 
# v1.0.2
* added shared logic for sef projects

# v1.0.0
* removed node, npm, yarn
* added make for building docker image
* added duration in slack message
* should use pre build docker image

# v0.0.14
* Added node, npm and yarn to docker

# v0.0.13
* Add verbose level

# v0.0.12
* Added custom tasks for sf - crobjob scanning and encore build

# v0.0.11
* Sym-linking the build directory instead of copying

# v0.0.10
* Added shared .env.local file used for building
* Added symlink for private_html (used for https)

# v0.0.9
* Altered build process. Also copying build files instead of symlinking

# v0.0.8
* Added wab-react. Used for apps and react websites 

# v0.0.7
* Added setup permissions
* Added deploy additional info
* Added slack notification when deployment starts
* Display task info when creating github release

# v0.0.6
* Support for WAB SF4 projects
* Moved shared tasks from SEF to bootstrap
* Set write mode to chmod since ACL is enabled default for IPS containers
* Copy vendor directory for SF

# v0.0.5
* Added functionality to create a release
* Added functionality to set composer authentication

# v0.0.4
* Added failure slack message
* Added supported role batch - will not run migration
* Forces annsicon for coloring support in github

# v0.0.3
* download deploy configuration from repo

# v0.0.2
* fixed typo in var
* adjusted slack message

# v0.0.1
* Initial version
