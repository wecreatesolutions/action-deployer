# import config.
# You can change the default config with `make cnf="config_special.env" build`
cnf ?= config.env
include $(cnf)
export $(shell sed 's/=.*//' $(cnf))

# import deploy config (APP_NAME, VERSION, DOCKER_REPO)
# You can change the default deploy config with `make dpl="deploy_special.env" release`
dpl ?= deploy.env
include $(dpl)
export $(shell sed 's/=.*//' $(dpl))

IMAGE := $(DOCKER_REPO)/$(APP_NAME)

# HELP
# This will output the help for each task
# thanks to https://marmelab.com/blog/2016/02/29/auto-documented-makefile.html
.PHONY: help build build-nc test release release-latest publish-version publish-latest version

help: ## This help.
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

.DEFAULT_GOAL := help

build: ## Build the image as $(IMAGE):$(VERSION)
	docker build -t $(IMAGE):$(VERSION) .

build-nc: ## Build the image without cache
	docker build --no-cache -t $(IMAGE):$(VERSION) .

test: ## Show the php and deployer version of the built image
	docker run --rm $(IMAGE):$(VERSION) sh -c 'php -v | head -1; deployer --version'

release: build-nc test publish-version ## Build without cache and push only the version tag (ghcr.io login needed)

release-latest: release publish-latest ## release + also push the latest tag

publish-version: ## Push the version tag
	docker push $(IMAGE):$(VERSION)

publish-latest: ## Tag the version as latest and push it
	docker tag $(IMAGE):$(VERSION) $(IMAGE):latest
	docker push $(IMAGE):latest

version: ## Output the current version
	@echo $(VERSION)
