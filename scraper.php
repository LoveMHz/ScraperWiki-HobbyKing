<?PHP
require 'scraperwiki.php';
require 'scraperwiki/simple_html_dom.php';

function _log($str) { echo $str."\n"; }

$startProductId = scraperwiki::get_var('currentId', -1);

if($startProductId == -1)
    _log('No previous saved position found. Starting from scratch.');
else
    _log('Resuming from product id' . $startProductId);

$morph_api_url = 'https://api.morph.io/LoveMHz/hobbyking_batteryidlist/data.json';
$morph_api_key = 'pLuYLEML5w7jsPUCMi9x';

$response = file_get_contents($morph_api_url.'?key='.$morph_api_key.'&query='.urlencode("select * from 'data' where id > $startProductId order by id asc"));
$batteries = json_decode($response, true);

$remainingCount = count($batteries);

_log('Found ' . $remainingCount . ' batteries left to be scraped.');

$maxPerRun = 100;
$loopCount = 0;

foreach($batteries as $bat) {
    if($loopCount > $maxPerRun) {
        _log('Ending run after ' . $maxPerRun . ' iterations.');
        break;
    }

    $productId = $bat['id'];
    _log('Retrieving ' . $productId);
	
    $html = scraperWiki::scrape('http://www.hobbyking.com/hobbyking/store/uh_viewItem.asp?idProduct=' . $productId);

    $dom = new simple_html_dom(); 
    $dom->load($html); 
    
    /* Get the product data (located in a span tag). Should only be one product data area! */
    $productDataAreasDom = $dom->find('SPAN[id=prodDataArea_' . $productId . ']');
    $productDataDom = $productDataAreasDom[0];

    $data = array();
    
    /* Loop over each row in the product data. */
    foreach ($productDataDom->find('tr') as $tr) {
        /* Get the columns for this row of info. */
        $columns = $tr->find('td');
    
        $attribute = $columns[0]->plaintext;
        $value = intval($columns[1]->plaintext);    
    
        /* Some rows are empty, and we should exclude them. */
        if (strlen($attribute) > 0) {
            $data['id'] = $productId;
            $data[$attribute] = $value;
        }
    }

    /* Get the price. */
    $priceDom = $dom->find('#price_lb');
    $price = floatval(str_replace('$', '', $priceDom[0]->plaintext));
    $data['price'] = $price;

    /* Calculate out a few extra fields: */
    $cells = $data['Config (s)'];
    $capacity = $data['Capacity(mAh)'];
    $energy = $cells * 3.7 * $capacity / 1000;
    $value = $energy / $price;

    $data['Energy (Wh)']  = $energy;
    $data['Value (Wh/$)'] = $value;
	
    $data['Amp Limit'] = $data['Discharge (c)'] * ($data['Capacity(mAh)'] / 1000);

    scraperwiki::save(['id'], [
		'id'           => $data['id'],
		'capacity'     => $data['Capacity(mAh)'],
		'config'       => $data['Config (s)'],
		'discharge'	   => $data['Discharge (c)'],
		'weight'	   => $data['Weight (g)'],
		'charge_rate'  => $data['Max Charge Rate (C)'],
		'length'	   => $data['Length-A(mm)'],
		'height'	   => $data['Height-B(mm)'],
		'width'		   => $data['Width-C(mm)'],
		'price'		   => $data['price'],
		'energy'	   => $data['Energy (Wh)'],
		'energy_value' => $data['Value (Wh/$)'],
		'amp_limit'    => $data['Amp Limit']
	]);
    scraperwiki::save_var('currentId', $productId);
    $loopCount++;
	
}

/* Check to see if we have scraped everything - if so, start again! */
$lastBattery = end($batteries);
if($lastBattery['id'] == $productId) {
    _log('All known batteries processed. Clearing progress marker so scraper can start again.');
    scraperwiki::save_var('currentId', -1);
}