# **<mark>Status bytes</mark>** 

## **Byte 0** : General purpose 

- 

- 

- 

- 

- 

- 

   - 0.7 = 1 Always 1. 

   - 0.6 = 0 Always 0. 

   - 0.5 = 1 General error - this is OR of all errors marked with #. 

   - 0.4 = 0 Always 0. 

   - 0.3 = 0 Always 0. 

   - 0.2 = 0 Always 0. 

- 0.1 = 1# Command code is invalid. 

- 0.0 = 1# Syntax error. 

## **Byte 1** : General purpose 

- 

- 

- 

- 

- 

- 

- 

   - 1.7 = 1 Always 1. 

   - 1.6 = 0 Always 0. 

   - 1.5 = 0 Always 0. 

   - 1.4 = 0 Always 0. 

   - 1.3 = 0 Always 0. 

   - 1.2 = 0 Always 0. 

   - 1.1 = 1# Command is not permitted. 

- 1.0 = 1# Overflow during command execution. 

## **Byte 2** : General purpose 

- 

- 

- 

- 

- 

- 

- 

   - 2.7 = 1 Always 1. 

   - 2.6 = 0 Always 0. 

   - 2.5 = 1 Nonfiscal receipt is open. 

   - 2.4 = 1 EJ nearly full. 

   - 2.3 = 1 Fiscal receipt is open. 

   - 2.2 = 1 EJ is full. 

   - 2.1 = 0 Always 0. 

- 2.0 = 1# End of paper. 

## **Byte 3** : Not used 

- 3.7 = 1 Always 1. 

- 3.6 = 0 Always 0. 

- 

- 

- 

- 

- 

   - 3.5 = 0 Always 0. 

   - 3.4 = 0 Always 0. 

   - 3.3 = 0 Always 0. 

   - 3.2 = 0 Always 0. 

   - 3.1 = 0 Always 0. 

- 3.0 = 0 Always 0. 

## **Byte 4** : Fiscal memory 

- 4.7 = 1 Always 1. 

- 4.6 = 0 Always 0. 

- 4.5 = 1 OR of all errors marked with ‘*’ from Bytes 4 и 5. 

- 4.4 = 1* Fiscal memory is full. 

- 4.3 = 1 There is space for less then 50 reports in Fiscal memory. 

- 4.2 = 1 Serial number and number of FM are set. 

- 4.1 = 1 Tax number is set. 

- 4.0 = 1* Error while writing in FM. 

## **Byte 5** : Fiscal memory 

- 5.7 = 1 Always 1. 

- 5.6 = 0 Always 0. 

- 5.5 = 0 Always 0. 

- 5.4 = 1 VAT are set at least once. 

- 5.3 = 1 ECR is fiscalized. 

- 5.2 = 0 Always 0. 

- 5.1 = 1 FM is formated. 

- 5.0 = 0 Always 0. 

**<mark>How to read command explanat</mark> i** **<mark>ons</mark>** 

```
o
```

```
o
```

```
o
```

```
o
```

```
o
```

**This is example command syntax:** 

{Parameter1}<SEP>{Parameter2}<SEP>{Parameter3}<SEP><DateTime><SEP> Note 

<SEP> - this tag must be inserted after each parameter to separate different parameters. It's value is '\t' (tab). It is the same for all commands. 

Mandatory parameters: 

 

 

```
o
```

- **Parameter1** - This parameter is mandatory, it must be filled; 

- **Parameter3** - This parameter is mandatory, it must be filled; 

**A** - Possible value of Parameter3; 

_Answer(1)_ - if Parameter3 has value 'A' see Answer(1); 

```
o
```

```
o
```

 

```
o
```

```
o
```

**B** - Possible value of Parameter3; _Answer(2)_ - if Parameter3 has value 'B' see Answer(2); **DateTime** - Date and time format: DD-MM-YY hh:mm:ss DST 

**DD** - Day **MM** - Month **YY** - Year **hh** - Hours **mm** - Minutes **ss** - Seconds **DST** - Text DST. If exist means that summer time is active. 

Optional parameters: 

 **Parameter2** - This parameter is optional it can be left blank, but separator must exist. Default: X; 

## Note 

If left blank parameter will be used with value, after "Default:" in this case 'X', but in some cases blank parameter may change the meaning of the command, which will be explained for each command; 

_Answer(X)_ - This is the default answer of the command. 

Under each command there will be list with possible answers. 

Answer when command fail to execute is the same for all commands, so it will not be explained after each command. **Answer when command fail to execute:** {ErrorStatus}<SEP>{ErrorCode}<SEP>{ErrorMessage}<SEP> 

 

```
o
```

```
o
```

   - **ErrorStatus** - Indicates an error; 

      - **'P'** - The command passed; 

      - **'F'** - The command failed; 

- **ErrorCode** - Code of the error from list with errors; 

- **ErrorMessage** - Text message of the error (if available); 

# **<mark>Command: 47 (2Fh)</mark>** 

Displaying text on upper line of the external display. **Parameters of the command:** 

{Text}<SEP> 

Mandatory parameters: 

- **Text** - 20 symbols which are sent directly to the external display; 

## **Answer:** 

{ErrorStatus}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

# **<mark>Command: 48 (30h)</mark>** 

Open fiscal receipt 

**Parameters of the command:** 

{OpCode}<SEP>{OpPwd}<SEP>{TillNmb}<SEP>{Storno}<SEP> 

Mandatory parameters: 

- **OpCode** - Operator number; 

- **OpPwd** - Operator password; 

- **TillNmb** - ignored, not used; 

- **Storno** - When '1' open storno receipt. Default: '0'; 

## **Answer:** 

{ErrorStatus}<SEP>{SlipNumber}<SEP> 

- **ErrorStatus** - Indicates an error; **'P'** - The command passed; **'F'** - The command failed; 

- **SlipNumber** - Current slip number (sales or storno); 

# **<mark>Command: 49 (31h)</mark>** 

## Registration of sale 

**Parameters of the command:** 

{PluName}<SEP>{TaxCd}<SEP>{Price}<SEP>{Quantity}<SEP>{fMacProduct}<SEP>{DiscountType} <SEP>{DiscountValue}<SEP> 

Mandatory parameters: {PluName},{TaxCd},{Price} 

- **PluName** - Name of product, up to 32 characters; 

- **TaxCd** - Tax code, 1-A, 2-Б, 3-В, 4-Г; 

- **Price** - Product price, with sign '-' at void operations; 

Optional parameters: {fMacProduct},{Quantity} 

- **fMacProduct** - flag '0'-Standart product, '1'-Macedonian product ( default:'0' ); 

- **Quantity** - Quantity of the product ( default: 1.000 ); 

- **DiscountType** - type of discount. 

   - **'0'** or empty - no discount; 

   - **'1'** - surcharge by percentage; 

   - **'2'** - discount by percentage; 

   - **'3'** - surcharge by sum; 

   - **'4'** - discount by sum; If {DiscountType} is not zero, {DiscountValue} has to contain value. The format must be a value with two decimals. 

- **DiscountValue** - value of discount. a number from 0.00 to 21474836.47 If 

   - {DiscountType} is zero or empty, this parameter must be empty. 

## **Answer:** 

{ErrorStatus}<SEP>{RecNumber}<SEP> 

- **ErrorStatus** - Indicates an error; **'P'** - The command passed; **'F'** - The command failed; 

- **RecNumber** - Current receipt number; 

# **<mark>Command: 51 (33h)</mark>** 

Subtotal 

**Parameters of the command:** 

{Print}<SEP>{Display}<SEP> Optional parameters: {Print},{Display} 

- **Print** - print out; 

   - **'0'** - default, no print out; 

   - **'1'** - the sum of the subtotal will be printed out; 

- **Display** - show the subtotal on the client display; 

   - **'0'** - default. 

   - **'1'** - the sum of the subtotal will appear on the display. 

## **Answer:** 

{ErrorStatus}<SEP>{SlipNumber}<SEP>{Subtotal}<SEP>{TaxA}<SEP>{TaxB}<SEP> 

{TaxC}<SEP>{TaxD}<SEP>{Tax_A}<SEP>{Tax_B}<SEP>{Tax_C}<SEP>{Tax_D}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

- **SlipNumber** - Current slip number (sales or storno); 

- **Subtotal** - Subtotal of the receipt; 

- **TaxX** - Recepts turnover by vat groups (standart products); 

- **Tax_X** - Recepts turnover by vat groups (macedonian products); 

# **<mark>Command: 53 (35h)</mark>** 

Payments and calculation of the total sum (TOTAL) 

**Parameters of the command:** 

{PaidMode}<SEP>{Amount}<SEP> 

- **PaidMode** - Type of payment; 

   - **'0'** - cash; 

   - `o` **'1'** - card; `o` **'2'** - credit 

- **Amount** - Amount to pay; 

## **Answer:** 

{ErrorStatus}<SEP>{Amount}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'D'** - The command passed, return when the paid sum is less than the sum of the receipt. The residual sum due for payment is returned to Amount; 

   - **'R'** - The command passed, return when the paid sum is greater than the sum of the receipt. A message “CHANGE” will be printed out and the change will be returned to Amount; 

   - **'F'** - The command failed; 

 **Amount** - The sum tendered; 

# **<mark>Command: 56 (38h)</mark>** 

Close fiscal receipt **Parameters of the command:** 

none 

## **Answer:** 

{ErrorStatus}<SEP>{SlipNumber}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

- **SlipNumber** - Current slip number (sales or storno); 

# **<mark>Command: 61 (3Dh)</mark>** 

Set date and time **Parameters of the command:** 

{DateTime}<SEP> 

Mandatory parameters: 

- **DateTime** - Date and time in format: "DD-MM-YY hh:mm:ss DST"; 

   - **DD** - Day; 

   - **MM** - Month; 

   - **YY** - Year; 

   - **hh** - Hour; 

   - **mm** - Minute; 

   - **ss** - Second; 

   - **DST** - Text "DST" if exist time is Summer time; 

## **Answer:** 

{ErrorStatus}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

# **<mark>Command: 62 (3Eh)</mark>** 

Read date and time 

**Parameters of the command:** 

none 

## **Answer:** 

{ErrorStatus}<SEP>{DateTime}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

- **DateTime** - Date and time in format: "DD-MM-YY hh:mm:ss DST"; 

   - **DD** - Day; 

   - `o` **MM** - Month; 

   - **YY** - Year; 

- **hh** - Hour; 

- **mm** - Minute; 

- **ss** - Second; 

- **DST** - Text "DST" if exist time is Summer time; 

# **<mark>Command: 64 (40h)</mark>** 

Information on the last fiscal entry **Parameters of the command:** 

{Type}<SEP> 

- **Type** - Type of returned data. Default: 0; 

   - 0 - Turnover on TAX group; 

   - 1 - Storno turnover on TAX group; 

   - 2 - Amount on TAX group; 

   - 3 - Storno amount on TAX group; 

   - 4 - Turnover on TAX group ( macedonian products); 

   - 5 - Storno turnover on TAX group ( macedonian products); 

   - 6 - Amount on TAX group ( macedonian products); 

   - 7 - Storno amount on TAX group ( macedonian products); 

## **Answer:** 

{ErrorStatus}<SEP>{nRep}<SEP>{SumA}<SEP>{SumB}<SEP>{SumC}<SEP>{SumD}<SEP>{Date} <SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

- **nRep** - Number of report; 

- **SumX** - Depend on **Type** . X is the letter of TAX group; 

- **Date** - Date of fiscal record in format DD-MM-YY; 

# **<mark>Command: 69 (45h)</mark>** 

Reports 

**Parameters of the command:** {ReportType}<SEP> Mandatory parameters: 

- **ReportType** - Report type; 

   - **X** - X report; 

   - **Z** - Z report; 

## **Answer:** 

{ErrorStatus}<SEP>{nRep}<SEP> {TotA}<SEP>{TotB}<SEP>{TotC}<SEP>{TotD}<SEP> {TotNegA}<SEP>{TotNegB}<SEP>{TotNegC}<SEP>{TotNegD}<SEP> 

{Tot_A}<SEP>{Tot_B}<SEP>{Tot_C}<SEP>{Tot_D}<SEP> 

{TotNeg_A}<SEP>{TotNeg_B}<SEP>{TotNeg_C}<SEP>{TotNeg_D}<SEP> 

 **ErrorStatus** - Indicates an error; 

`o` **'P'** - The command passed; `o` **'F'** - The command failed; 

- **nRep** - Number of Z-report; 

- **TotX** - Total sum accumulated by TAX group X - fiscal operations; 

- **TotNegX** - Total sum accumulated by TAX group X - storno operations; 

- **Tot_X** - Total sum accumulated by TAX group X - fiscal operations (only macedonian products); 

- **TotNeg_X** - Total sum accumulated by TAX group X - storno operations (only macedonian products); 

# **<mark>Command: 70 (46h)</mark>** 

Cash in and Cash out operations 

**Parameters of the command:** 

{Type}<SEP>{Amount}<SEP> 

- **Type** - type of operation; 

   - **'0'** - cash in; 

   - **'1'** - cash out; 

- **Amount** - the sum; **Answer:** 

{ErrorStatus}<SEP>{CashSum}<SEP>{CashIn}<SEP>{CashOut}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

- **CashSum** - cash in safe sum; 

- **CashIn** - total sum of cash in operations; 

- **CashOut** - total sum of cash out operations; 

# **<mark>Command: 74 (4Ah)</mark>** 

Reading the Status 

**Parameters of the command:** 

none 

## **Answer:** 

{ErrorStatus}<SEP>{StatusBytes}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - `o` **'F'** - The command failed; 

- **StatusBytes** - Status Bytes. 

# **<mark>Command: 76 (4Ch)</mark>** 

Status of the fiscal transaction 

**Parameters of the command:** 

none 

## **Answer:** 

- {ErrorStatus}<SEP>{IsOpen}<SEP>{Items}<SEP>{Amount}<SEP>{Payed}<SEP> 

   - **ErrorStatus** - Indicates an error; 

      - **'P'** - The command passed; 

      - `o` **'F'** - The command failed; 

- **IsOpen** - 1 - Receipt is open, 0 - receipt is closed; 

- **Items** - number of sales registered on the current or the last fiscal receipt; 

- **Amount** - The sum from the current or the last fiscal receipt; 

- **Payed** - The sum payed for the current or the last receipt 

# **<mark>Command: 107 (6Bh)</mark>** 

Defining and reading items **Parameters of the command:** {Option}<SEP>{Parameters}<SEP> Mandatory parameters: {Option} 

- **I** - Items information; Syntax: 

- {Option}<SEP> _Answer(3)_ 

 **P** - Item programming; Syntax: {Option}<SEP>{PLU}<SEP>{TaxGr}<SEP>{Dep}<SEP>{Group}<SEP>{PriceType}<SEP>{Price} <SEP>{AddQty}<SEP>{Quantity}<SEP>{Bar1}<SEP>{Bar2}<SEP>{Bar3}<SEP>{Bar4}<SEP>{Name} <SEP> 

Mandatory parameters: 

- **PLU** - Item number; 

- `o` **TaxGr** - VAT group; `o` **Dep** - Department; `o` **Group** - Stock group; `o` **PriceType** - Price type; `o` **Price** - Price; `o` **Quantity** - Stock quantity; `o` **Name** - Item name; 

Optional parameters: 

`o` **AddQty** - A byte with value 'A', `o` **BarX** - Barcode X; _Answer(1)_  **A** - Change of the available quantity for item; Syntax: {Option}<SEP>{PLU}<SEP>{Quantity}<SEP> Mandatory parameters: `o` **PLU** - Item number; `o` **Quantity** - Stock quantity; _Answer(1)_  **D** - Item deleting; Syntax: {Option}<SEP>{firstPLU}<SEP>{lastPLU}<SEP> Mandatory parameters: `o` **firstPLU** - First item to delete; If this parameter has value 'A', all items will be deleted(lastPLU must be empty). 

Optional parameters: `o` **lastPLU** - last item to delete. Default: {firstPLU}; ; _Answer(1)_ 

 **R** - Reading item data; Syntax: {Option}<SEP>{PLU}<SEP> Mandatory parameters: `o` **PLU** - Item number; _Answer(2)_  **F** - Returns data about the first found programmed item; Syntax: {Option}<SEP>{PLU}<SEP> Optional parameters: `o` **PLU** - Item number. Default: 0; _Answer(2)_  **L** - Returns data about the last found programmed item; Syntax: {Option}<SEP>{PLU}<SEP> Optional parameters: `o` **PLU** - Item number. Default: 100000; _Answer(2)_  **N** - Returns data for the next found programmed item; Syntax: {Option}<SEP> Note The same command with option 'F' or 'L' must be executed first. This determines whether to get next('F') or previous ('L') item. _Answer(2)_  **f** - Returns data about the first found item with sales on it; Syntax: {Option}<SEP>{PLU}<SEP> Optional parameters: `o` **PLU** - Item number. Default: 0; _Answer(2)_  **l** - Returns data about the last found item with sales on it; Syntax: {Option}<SEP>{PLU}<SEP> Optional parameters: `o` **PLU** - Item number. Default: 100000; _Answer(2)_  **n** - Returns data for the next found item with sales on it; Syntax: {Option}<SEP> Note The same command with option 'f' or 'l' must be executed first. This determines whether to get next('f') or previous ('l') item. _Answer(2)_  **X** - Find the first not programmed item; Syntax: {Option}<SEP>{PLU}<SEP> Optional parameters: 

`o` **PLU** - Item number. Default: 0; 

_Answer(4)_ 

 **x** - Find the last not programmed item; Syntax: {Option}<SEP>{PLU}<SEP> Optional parameters: 

`o` **PLU** - Item number. Default: 100000; 

_Answer(4)_ **Answer(1)** : {ErrorStatus}<SEP> 

 **ErrorStatus** - Indicates an error; 

`o` **'P'** - The command passed; 

`o` **'F'** - The command failed; 

**Answer(2)** : 

{ErrorStatus}<SEP>{PLU}<SEP>{TaxGr}<SEP>{Dep}<SEP>{Group}<SEP>{PriceType}<SEP>{Price} <SEP>{Turnover}<SEP>{SoldQty}<SEP>{StockQty}<SEP>{Bar1}<SEP>{Bar2}<SEP>{Bar3} <SEP>{Bar4}<SEP>{Name}<SEP> 

- **ErrorStatus** - Indicates an error; 

`o` **'P'** - The command passed; `o` **'F'** - The command failed; 

- **PLU** - Item number; 

- **TaxGr** - VAT group ( number ); 

- **Dep** - Department; 

- **Group** - Stock group; 

- **PriceType** - Price type; 

- **Price** - Price; 

- **Turnover** - Accumulated amount of the item; 

- **SoldQty** - Sold out quantity; 

- **StockQty** - Current quantity; 

- **BarX** - Barcode X; 

- **Name** - Item name; 

## **Answer(3)** : 

{ErrorStatus}<SEP>{Total}<SEP>{Prog}<SEP>{NameLen}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

- **Total** - Total count of the programmable items; 

- **Prog** - Total count of the programmed items; 

- **NameLen** - Maximum length of item name; 

**Answer(4)** : 

{ErrorStatus}<SEP>{PLU}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

`o` **'F'** - The command failed; 

 **PLU** - Item number; 

# **<mark>Command: 94 (5Еh)</mark>** 

Fiscal memory report by date **Parameters of the command:** 

{Type}<SEP>{Start}<SEP>{End}<SEP> Mandatory parameters: 

- **Type** - 0 - short; 1 - detailed; 

Optional parameters: 

- **Start** - Start date. Default: Date of fiscalization; 

- **End** - End date. Default: Current date; 

## **Answer:** 

{ErrorStatus}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

# **<mark>Command: 95 (5Fh)</mark>** 

Fiscal memory report by number of Z-report 

**Parameters of the command:** 

{Type}<SEP>{First}<SEP>{Last}<SEP> 

Mandatory parameters: 

- **Type** - 0 - short; 1 - detailed; 

Optional parameters: 

- **First** - First block in the report. Default: 1; 

- **Last** - Last block in the report. Default: number of last Z report; 

## **Answer:** 

{ErrorStatus}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

# **<mark>Command: 106 (6Ah)</mark>** 

Drawer opening 

**Parameters of the command:** 

{mSec}<SEP> Optional parameters: 

- **mSec** - The length of the impulse in milliseconds. ( from 0 to 65 535 ) 

## **Answer:** 

{ErrorStatus}<SEP> 

- **ErrorStatus** - Indicates an error; 

   - **'P'** - The command passed; 

   - **'F'** - The command failed; 

