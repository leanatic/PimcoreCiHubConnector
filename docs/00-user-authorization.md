# User authorization
Authorization of users with the Ci-HUB Connector is now done with an individual user token.

The token can be generated both in the user card and in the users admin panel during configuration.
![My Profile](images/token.png "My Profile")
![Users Admin Panel](images/delivery_settings.png "Users Admin Panel")
## Pimcore Studio (Pimcore 2026, no Classic Admin)
Pimcore 2026 has no Classic Admin, so the user card above is not available. In Studio, open
System > User & Roles > CI HUB Tokens (needs the permission "users"; only admins change the tokens of admins). It lists
the users, generates a token (shown once, with a copy button) and revokes it.

## Console
The same on the console (both use `UserTokenManager`):

```bash
bin/console datahub:ci-hub:token list                     # users with a token (tokens are not shown)
bin/console datahub:ci-hub:token generate <user>          # creates a token and prints it once
bin/console datahub:ci-hub:token generate <user> --force  # replaces an existing token
bin/console datahub:ci-hub:token revoke <user>            # deletes the token
```

The token acts with the rights of the user (workspaces of the user apply). It only works while the user is active.

## Simple REST endpoints
CI HUB clients set up for Pimcore's DataHub Simple REST adapter call
`/pimcore-datahub-webservices/simplerest/{config}/tree-items|search|get-element|download-asset`. The bundle answers
these for configs of type `ciHub`, with either the API key of the config (Delivery settings) or a user token. The API
key of the config only opens these read endpoints; upload, lock and the other endpoints need a user token.
