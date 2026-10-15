# Legacy deployment instructions — do not use

The former Yandex Cloud/Docker instructions and the old direct server update
procedure are retired. They do not describe the current IHC installation and
must not be used for AV Rescue production.

The approved replacement is documented in:

- [docs/SAFE_MAX_DEPLOYMENT.md](docs/SAFE_MAX_DEPLOYMENT.md) for MAX;
- [docs/AVR_AI_CONTROLLER_INSTALL.md](docs/AVR_AI_CONTROLLER_INSTALL.md) for
  the independent AI controller;
- [docs/AVR_PROJECT_REGISTER.md](docs/AVR_PROJECT_REGISTER.md) for the required
  order of work and production gates.

Important: the prepared safe deployment has not been authorized or executed on
production. Never substitute the retired workflow, a root password, `sshpass`,
an in-place branch switch, or an automatic Excel/order repair.
