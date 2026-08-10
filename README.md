# WordPress Development Environment

### Requirements
- `PHP >=7.4.0`
- `Composer`
- `mysql >=5.7.0`
- `NVM` (`Node.js 14.x`)

### Local Development
The `.env` file is necessary for the entire process (use the `.env.example` file as reference).\
Once you have generated the `.env` file, run `make init`. After execution, the project is ready for launch and development.\
You can find all other important commands in the `Makefile` file.

### GitHub Actions
Before using workflows, you should define `secret` variables in the project repository settings ([docs](https://docs.github.com/en/actions/security-guides/using-secrets-in-github-actions)).
- For `deploy-to-prod`:
	- `PROD_FTP_HOST`;
	- `PROD_FTP_PATH`;
	- `PROD_FTP_NAME`;
	- `PROD_FTP_PWD`.
- For `deploy-to-dev`:
	- `DEV_FTP_HOST`;
	- `DEV_FTP_PATH`;
	- `DEV_FTP_NAME`;
	- `DEV_FTP_PWD`.

`create-release-zip` doesn't require additional variables and after execution will output a zip archive as [an artifact](https://docs.github.com/en/actions/using-workflows/storing-workflow-data-as-artifacts).

### Files for release
#### package.json
In this file you can find `"release":{}` which contains a list of directories and files that should be included in the release, it's used when executing the `deploy-to-dev` in the local environment and `create-release-zip` in all environments.

#### .github/workflows
In `deploy-to-prod.yml` and `deploy-to-dev.yml` files under `name: Deploy` you can find `exclude` which contains a list of directories and files to ignore during the deployment process.
