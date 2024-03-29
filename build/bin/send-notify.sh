#!/bin/bash
PREVIEW_LINK='https://raw.githack.com/wirecard/reports'
REPORT_FILE='report.html'
#choose slack channel depending on the gateway
if [[ ${GATEWAY} = "NOVA" ]]; then
  CHANNEL='shs-ui-nova'
elif [[ ${GATEWAY} = "API-WDCEE-TEST" ]]; then
  CHANNEL='shs-ui-api-wdcee-test'
elif [[  ${GATEWAY} = "API-TEST" ]]; then
   CHANNEL='shs-ui-api-test'
elif [[  ${GATEWAY} = "TEST-SG" ]]; then
   CHANNEL='shs-ui-test-sg'
elif [[  ${GATEWAY} = "SECURE-TEST-SG" ]]; then
   CHANNEL='shs-ui-secure-test-sg'
fi

#send information about the build
curl -X POST -H 'Content-type: application/json' \
    --data "{'text': 'Build Failed. Magento version: ${MAGENTO_VERSION}\n
     Build URL : ${TRAVIS_JOB_WEB_URL}\n
    Build Number: ${TRAVIS_BUILD_NUMBER}\n
    Branch: ${TRAVIS_BRANCH}\n
    Report link: ${PREVIEW_LINK}/${SCREENSHOT_COMMIT_HASH}/${PROJECT_FOLDER}/${GATEWAY}/${TODAY}/${REPORT_FILE}',
    'channel': '${CHANNEL}'}" ${SLACK_ROOMS}
