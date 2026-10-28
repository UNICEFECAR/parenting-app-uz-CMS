Environment config splits. Site config lives in `config/sync`; only config that
differs per environment goes here, one folder per split:

| Split | Folder | Active on |
|-------|--------|-----------|
| `local` | `config/envs/local` | DDEV (`IS_DDEV_PROJECT=true`) |
| `dev` | `config/envs/dev` | Acquia dev (`AH_SITE_ENVIRONMENT` dev/ode) |
| `stage` | `config/envs/stage` | Acquia stage (`AH_SITE_ENVIRONMENT` test/stg/stage) |
| `prod` | `config/envs/prod` | Acquia prod (`AH_SITE_ENVIRONMENT` prod/01live) |

The active split is set in `docroot/sites/default/site.splits.php`. The `local`
split holds the development modules (`config_devel`).

To move config into a split, add it to the split's module, complete or partial
list (`/admin/config/development/configuration/config-split`), then run
`drush cex` on that environment.
