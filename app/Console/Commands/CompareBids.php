<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CompareBids extends Command{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'bids:compare';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Compare bids between 3 systems';

  /**
   * Create a new command instance.
   *
   * @return void
   */
  public function __construct(){
    parent::__construct();
  }

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle(){

    $this->output->progressStart(6);

		$data1 = $this->getData('https://gist.githubusercontent.com/7twelve/39fa68b87d3ea87a1ac5b6c0567882ff/raw/8dec5cbbfed67d146f4848868329e51722776941/json_1.json');
    $this->output->progressAdvance();

		$data2 = $this->getData('https://gist.githubusercontent.com/7twelve/e53d2febad36bc77c9bab399961ac1d5/raw/0a308097bf089bf7572de6bf90d74c183148d54f/json_2.json');
    $this->output->progressAdvance();

		$data3 = $this->getData('https://gist.githubusercontent.com/7twelve/e6f8f9b6795308dd13fcfca1e90cd8ab/raw/10753951e7a897a26b72ebf757e9fdacecea978f/json_3.json');
    $this->output->progressAdvance();

		// Get differences between truth and source 2
		$diff2 = $this->compareData2($data1, $data2);
    $this->output->progressAdvance();

		// Get differences between truth and source 3
		$diff3 = $this->compareData3($data1, $data3);
    $this->output->progressAdvance();

		// Prepare table with all difference data
    $headers = ['Lot Number', 'Field', 'Destination', 'Diff'];
    $diff = array_merge($diff2, $diff3);

		// Sort by Lot Number
		usort($diff, function($a, $b) {
			return $a[0] <=> $b[0];
		});

		// Show table of differences
    $this->output->progressFinish();
    $this->line('Bid differences');
    $this->table($headers, $diff);
  }

  /**
   * Get the data from an API endpoint.
   *
   * @return array
   */
  private function getData($url){
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$data = curl_exec($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if($http_code == 200){
	    return json_decode($data);
		}

		$this->error('No data from API: ' . $url);
		return [];
  }

  /**
   * Get the match from the API data.
   *
   * @return array
   */
  private function getSimpleMatch($search, $search_field, $search_value){
		foreach($search as $result){
			if($result->$search_field == $search_value){
				return $result;
			}
		}

		return [];
  }

  /**
   * Get the match from the API data.
   *
   * @return array
   */
  private function getComplexMatch($search, $search_field1, $search_value1, $search_field2, $search_value2){
		foreach($search as $result){
			if($result->$search_field1 == $search_value1 && $result->$search_field2 == $search_value2){
				return $result;
			}
		}

		return [];
  }

  /**
   * Compare the data from truth to source 2.
   *
   * @return array
   */
  private function compareData2($truth, $lie){
    $diff = [];

		$url = 'https://gist.githubusercontent.com/7twelve/0db2de9ebde4bfbcdd0894797faf3748/raw/843d5a7f49702e765a5c3e0f74aba4811d808201/users_by_fd_key.json';
		$user_correlation = $this->getData($url);

		// Uniqueness of records is defined as a combination of the `item_fd_key` and `user_uuid_text` fields. Field name normalization between the three will be required, as each system calls the fields something different in most cases. In order to get the uuid_text of the user for json_2.json, there is a users_by_fd_key.json and users_by_uuid_text.json which are already keyed by the noted field name.
		foreach($truth as $truth_bid){
			$lie_user = $this->getSimpleMatch($user_correlation, 'uuid_text', $truth_bid->user_fd_key);
			if(empty($lie_user)){
				continue;
			}

			$matching_lie = $this->getSimpleMatch($lie, 'user_fd_key', $lie_user->fd_key);
			if(empty($matching_lie)){
				continue;
			}

			// For the bid type, on json_2 a bid_amount of ‘P’ signifies that the type is a phone bid
			if($truth_bid->bid_amount !== $matching_lie->bid_amount){
	      $diff[] = [$matching_lie->LotNumber, 'Bid Amount', '2', $truth_bid->bid_amount . ' ⇒ ' . $matching_lie->bid_amount];
			}

			// On json_1 and json_2, if the type is a phone bid, the amount will be using the bid_to_amount field rather than the bid_amount field
			if($truth_bid->bid_to_amount !== $matching_lie->BidAmount){
	      $diff[] = [$matching_lie->LotNumber, 'Bid to Amount', '2', $truth_bid->bid_to_amount . ' ⇒ ' . $matching_lie->bid_to_amount];
			}
		}

		return $diff;
  }

  /**
   * Compare the data from truth to source 3.
   *
   * @return array
   */
  private function compareData3($truth, $lie){
    $diff = [];

		$url = 'https://gist.githubusercontent.com/7twelve/79c5f96c59e5786fc31a4aac78b5575f/raw/23b1faeaedd892a3ff2e29b9e6c5b8d1ccfee239/users_by_uuid_text.json';
		$user_correlation = $this->getData($url);

		// Uniqueness of records is defined as a combination of the `item_fd_key` and `user_uuid_text` fields.
		foreach($truth as $truth_bid){
			$lie_user = $this->getSimpleMatch($user_correlation, 'uuid_text', $truth_bid->user_uuid_text);
			if(empty($lie_user)){
				continue;
			}

			$matching_lie = $this->getComplexMatch($lie, 'Lot_FD_Key', $lie_user->fd_key, 'Bidder_FD_Key', $lie_user->uuid_text);
			if(empty($matching_lie)){
				continue;
			}

			// For the bid type, in json_3 a BidType of ‘9’ signifies the type is a phone bid.
			if($truth_bid->bid_amount == 'P' && $matching_lie->BidType !== '9'){
	      $diff[] = [$matching_lie->LotNumber, 'Bid Type', '3', $truth_bid->bid_amount . ' ⇒ ' . $matching_lie->BidType];
			}

			// On json_1, if the type is a phone bid, the amount will be using the bid_to_amount field rather than the bid_amount field
			if($truth_bid->bid_to_amount !== $matching_lie->BidAmount){
	      $diff[] = [$matching_lie->LotNumber, 'Bid Amount', '3', $truth_bid->bid_to_amount . ' ⇒ ' . $matching_lie->BidAmount];
			}

			if($truth_bid->auction_fd_key !== $matching_lie->Auction_FD_Key){
	      $diff[] = [$matching_lie->LotNumber, 'Auction FD Key', '3', $truth_bid->auction_fd_key . ' ⇒ ' . $matching_lie->Auction_FD_Key];
			}
		}

		return $diff;
  }
}
