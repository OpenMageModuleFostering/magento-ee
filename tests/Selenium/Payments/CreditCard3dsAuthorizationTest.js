/**
 * Shop System Plugins:
 * - Terms of Use can be found under:
 * https://github.com/wirecard/magento-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/magento-ee/blob/master/LICENSE
 */
let mysql = require('mysql');
const { By, until, Key } = require('selenium-webdriver');
const {
  waitForAlert,
  getDriver,
  asyncForEach,
  placeOrder,
  checkConfirmationPage,
  choosePaymentMethod,
  fillOutGuestCheckout,
  addProductToCartAndGotoCheckout,
  chooseFlatRateShipping
} = require('../common');
const { config } = require('../config');
let driver;

describe('Credit Card 3-D Secure Authorization test', () => {
  before(async () => {
    driver = await getDriver('credit card 3ds');
  });

  const paymentLabel = config.payments.creditCard3ds.label;
  const formFields = config.payments.creditCard3ds.fields;

  it('should check the credit card 3ds authorization payment process', async () => {

    let con = mysql.createConnection({
      host: "127.0.0.1",
      user: "travis",
      password: "",
      database: "magento"
    });

    con.connect(function(err) {
      if (err) throw err;
      console.log("Connected!");
      let sql = "INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default',0,'payment/wirecardee_paymentgateway_creditcard/transaction_type','reserve')";
      con.query(sql, function (err, result) {
        if (err) throw err;
        console.log("1 record inserted");
      });
    });

    await addProductToCartAndGotoCheckout(driver, '/flapover-briefcase.html');
    await fillOutGuestCheckout(driver);
    await chooseFlatRateShipping(driver);
    await choosePaymentMethod(driver, 'p_method_wirecardee_paymentgateway_creditcard', paymentLabel);
    await placeOrder(driver);

    // Fill out credit card iframe
    await driver.wait(until.elementLocated(By.className('wirecard-seamless-frame')), 20000);
    await driver.wait(until.ableToSwitchToFrame(By.className('wirecard-seamless-frame')));
    await driver.wait(until.elementLocated(By.id('account_number')), 20000);
    await asyncForEach(Object.keys(formFields), async field => {
      await driver.findElement(By.id(field)).sendKeys(formFields[field]);
    });
    await driver.findElement(By.css('#expiration_month_list > option[value=\'01\']')).click();
    await driver.findElement(By.css('#expiration_year_list > option[value=\'' + config.payments.creditCard.expirationYear + '\'')).click();
    await driver.switchTo().defaultContent();
    await driver.wait(until.elementLocated(By.id('wirecardee-credit-card--form-submit')));
    await driver.findElement(By.id('wirecardee-credit-card--form-submit')).click();

    // Enter 3d secure password
    await driver.wait(until.elementLocated(By.id('password')), 20000);
    await driver.findElement(By.id('password')).sendKeys(config.payments.creditCard3ds.password, Key.ENTER);

    await waitForAlert(driver, 10000);
    await checkConfirmationPage(driver, 'Thank you for your purchase!');

    con.connect(function(err) {
      if (err) throw err;
      con.query("SELECT * FROM core_config_data", function (err, result, fields) {
        if (err) throw err;
        console.log(result);
      });
    });
  });

  after(async () => driver.quit());
});
