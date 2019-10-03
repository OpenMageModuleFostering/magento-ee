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

  let con = mysql.createConnection({
    host: "127.0.0.1",
    user: "travis",
    password: "",
    database: "magento"
  });

  // update statment
  let sql = `UPDATE core_config_data
           SET value = ?
           WHERE path = ?`;

  let data = ['reserve', 'payment/wirecardee_paymentgateway_creditcard/transaction_type'];

// execute the UPDATE statement
  con.query(sql, data, (error, results, fields) => {
    if (error){
      return console.error(error.message);
    }
    console.log('Database has been updated!');
  });

  // con.connect(function(err) {
  //   if (err) throw err;
  //   console.log("Database connection established.");
  //   let sql = "UPDATE core_config_data SET value = 'reserve' WHERE path = 'payment/wirecardee_paymentgateway_creditcard/transaction_type'";
  //   con.query(sql, function (err, result) {
  //     if (err) throw err;
  //     console.log("Transaction type successful changed.");
  //   });
  // });

  con.query("SELECT value FROM core_config_data WHERE path = 'payment/wirecardee_paymentgateway_creditcard/transaction_type'", function (err, result, fields) {
    if (err) throw err;
    console.log(result);
  });

  con.end();

  it('should check the credit card 3ds authorization payment process', async () => {

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

    let con = mysql.createConnection({
      host: "127.0.0.1",
      user: "travis",
      password: "",
      database: "magento"
    });

    // con.query("SELECT * FROM sales_payment_transaction", function (err, result, fields) {
    //   if (err) throw err;
    //   console.log(result);
    // });

    con.query("SELECT * FROM core_config_data WHERE path LIKE '%creditcard%'", function (err, result, fields) {
      if (err) throw err;
      console.log(result);
    });

    // console.log("Drugi dio");

    // con.query("SELECT txn_type FROM sales_payment_transaction ORDER BY transaction_id DESC LIMIT 1", function (err, result, fields) {
    //   if (err) throw err;
    //   console.log(result[0].@txn_type);
    // });

    con.end();
  });

  after(async () => driver.quit());
});
