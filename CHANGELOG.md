### 1.0.8 2018-01-25

* Fixed non-visa cards from not being inserted to Blesta's DB correctly

### 1.0.7 2017-05-25

* Return last4, type and expiration for storage in Blesta DB

### 1.0.6 2017-05-25

* Updated Stripe PHP SDK to version 4.9.1
* Add ability to pass merchant_token (with blesta edits) to gateway

### 1.0.5 2016-10-11

* Use invoice `id_code` so to not expose the ID on statement descriptions
* Clean up and add additional, likely redundant error checks for pull request #1

### 1.0.4 2016-09-23

* Updated Stripe PHP SDK to version 3.23.0
* Remove invoice IDs from statement descriptors

### 1.0.3 2016-07-20

* Patches statement description for multi-invoice payments

### 1.0.2 2016-07-15

* Updated Stripe PHP SDK to version 3.17.0
* Patch statement descriptor when no invoice amounts are set (credit payments)
* Added description to all payments alongside statement descriptor
