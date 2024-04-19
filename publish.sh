#!/bin/bash
PROJECT_DIR=$(pwd)
cd $PROJECT_DIR/_default_project && ../hugo/hugo --cleanDestinationDir -DEF && cd $PROJECT_DIR/_default_project/public/edit && ln -s ../../../hugocms hugocms
