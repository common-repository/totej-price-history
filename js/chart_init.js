const ctx = document.getElementById('myChart');
const data = {
labels: labels,
datasets: [{
  label: totej_label_text,
  data: totej_prices_array,
  height:200,
  fill: false,
  borderColor: totej_prices_base_color,
  tension: 0.1
}]
};
const config = {
type: 'line',
data: data,
};
new Chart(ctx, config);

jQuery( '.single_variation_wrap' ).on( 'show_variation', function( event, variation ) {
	console.log( variation );
});