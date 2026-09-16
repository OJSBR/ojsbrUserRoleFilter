#!/bin/bash

set -e

npx cypress run  --headless --browser chrome  --config '{"specPattern":["plugins/generic/ojsbrUserRoleFilter/cypress/tests/functional/*.cy.js"]}'
