Note: This Gateway is no longer maintained. All of its features (and more, including SCA compliance) are now available in the latest versions of Blesta, in the [Stripe Payments](https://docs.blesta.com/display/user/Stripe+Payments) Gateway.

---

# Blesta: Stripe (plus) Gateway
Forked version of the original Stripe gateway which includes updated offsite storage and ACH payments.

## Installation
Download the latest release version from our [Releases](https://github.com/nodecraft/stripe_plus_gateway/releases) and then simply upload the `stripe_plus_gateway` folder to `~/components/gateways/merchant` directory on your Blesta installation.

### Feature changes from Original Gateway
This gateway has been rewritten from the original version which was distributed with Blesta. The major changes are as follows:
 - Only creates one Stripe Customer per Client Contact, rather than one Stripe Customer per Credit Card
 - Only supports offsite card storage, onsite storage is removed
 - Supports ACH payments*
 - Updated Stripe API PHP SDK to version `4.9.1`
 - Utilizes Stripe API Version `2017-05-25`
 - Adds API key environment selection (test vs live)
 - Updated Currency list to Stripe Documentation
 - Does not utilize Blesta's `$client_reference_id` lookup to Stripe customer ID. This can cause multiple customer accounts if the user deletes all payment methods. Uses added MySQL table `stripe_plus_meta`

### Pros:
 -  Prevents your Stripe account from having "dead" data by attaching one customer per payment source. This enhances your ability to fight fraud.
 -  Added security by preventing payment source information from being stored locally

### Cons:
 -  *ACH Payments by Stripe require "verification" before payments are accepted. Blesta does not provide any methods for this process to take place. You will need to manually verify the bank account with your customer until this is improved or a plugin created.

### Roadmap:
- [ ] Add ACH verification if Blesta implements methods on Gateway
   
### License
This module is majoritively a complete rewrite of the original module. A few minor methods have been retained from the original codebase. As compliance would require, we have bound this codebase to the original Blesta License which can be found on their website: http://www.blesta.com/license/. Any changes to this codebase or distribution will be held to the same license.
