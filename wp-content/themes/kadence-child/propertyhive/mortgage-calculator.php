<?php
/**
 * The Template for displaying the mortgage calculator form and results
 *
 * Override this template by copying it to yourtheme/propertyhive/mortgage-calculator.php
 *
 * NOTE: For the calculation to still occur it's important that most classes, ids and input names remain unchanged
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
?>

<div class="mortgage-calculator">

    <form onsubmit="return mySubmitFunction(event)">
        <div class="calculator-row calculator-row-1">
            <div class="calculator-field-group">
                <label><?php echo __( 'Property Price', 'propertyhive' ); ?> (&pound;)</label>
                <input type="text" name="purchase_price" value="<?php echo $atts['price']; ?>" placeholder="Enter purchase price" required>
            </div>
            <div class="calculator-field-group">
                <label><?php echo __( 'Deposit', 'propertyhive' ); ?> (&pound;)</label>
                <input type="text" name="deposit_amount" value="" placeholder="Enter deposit amount" required>
            </div>
        </div>

        <div class="calculator-row calculator-row-2">
            <div class="calculator-field-group">
                <label><?php echo __( 'Interest Rate', 'propertyhive' ); ?> (%)</label>
                <input type="text" name="interest_rate" value="" placeholder="Enter interest rate (eg. 3.2)" required>
            </div>
            <div class="calculator-field-group">
                <label><?php echo __( 'Repayment Period', 'propertyhive' ); ?> (<?php echo __( 'years', 'propertyhive' ); ?>)</label>
                <input type="text" name="repayment_period" value="" placeholder="Enter loan length" required>
            </div>
        </div>

        <button><?php echo __( 'Calculate', 'propertyhive' ); ?></button>
    </form>
    <script type="text/javascript">
        function mySubmitFunction(e) {
            e.preventDefault();
            return false;
        }
    </script>

    <div class="mortgage-calculator-results" id="results" style="display:none">

        <h4><?php echo __( 'Your approximate mortgage cost is', 'propertyhive' ); ?>:</h4>

        <div class="calculator-results-container">
            <div class="calculator-result-row">
                <p class="calculator-result-label"><?php echo __( 'Repayment', 'propertyhive' ); ?></p>
                <input class="calculator-result-field" type="text" name="repayment" value="" placeholder="" disabled>
            </div>
            <div class="calculator-result-row">
                <p class="calculator-result-label"><?php echo __( 'Interest Only', 'propertyhive' ); ?></p>
                <input class="calculator-result-field" type="text" name="interest" value="" placeholder="" disabled>
            </div>
        </div>
    </div>
</div>