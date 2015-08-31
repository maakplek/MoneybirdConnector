# Maakplek Moneybird Connector
This connector connects a Google Form (through Zapier) with Moneybird to create automated (onetime & recurring) invoices for new members. For an example form, see http://goo.gl/forms/7b4Ff09Vsz.

Just fill out the subdomain, username, password and the tax rate ID's (these can be found in your Moneybird account) and hook up the Zap from Zapier to the URL where you've put this script.

Set up two Zaps; one for a new row in the sheet and one for an updated row. Append ?update=true to the Zap for the updated row. This way, the contact in Moneybird will be updated. The notify email is used to send notifications of failed invoice creation attempts to.

Also make sure to name the fields correctly. Either name them correctly in your form when creating it (see the example form) or rename the fields in the script.

- made by @peterjaap
