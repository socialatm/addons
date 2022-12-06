<div id="panel_box_navigation" style="display: none;">
	<ul class="nav nav-pills bg-light">
		<li class="nav-item dropdown flashcards_nav">
			<a class="nav-pill nav-link dropdown-toggle" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false"><i class="fa fa-2x fa-fw fa-graduation-cap"></i></a>
			<div class="dropdown-menu">
				<a class="dropdown-item" id="flashcards_new_box">New Box</a>
				<a class="dropdown-item" id="flashcards_edit_box">Edit Box</a>
				<a class="dropdown-item" id="flashcards_show_boxes">List Boxes</a>
				<div class="dropdown-divider"></div>
				<a class="dropdown-item .disabled" id="flashcards_search_boxes">Search</a>
				<div class="dropdown-divider"></div>
				<a class="dropdown-item" id="flashcards_show_help">Help</a>
			</div>
		</li>
		<div class="navbar-brand nav-pill">
			<span id="flashcards_navbar_brand" class="flashcards_nav"></span>
			<button class="btn flashcards_nav" id="button_flashcards_learn_play"><i class="fa fa-play fa-lg"></i> <sup><span id="span_flashcards_cards_due"></span></sup></button>
		</div>
		<button class="btn btn-default nav-pill ml-auto" id="button_share_box">
			<i class="fa fa-refresh fa-lg"></i>
			<span id="button_share_box_counter"></span>
		</button>
		<button class="btn btn-default nav-pill ml-auto" id="button_flashcards_save_box" style="display: none;">
			<i class="fa fa-save fa-lg"></i> Save
		</button>
		<button class="btn btn-default nav-pill ml-auto" id="button_flashcards_close" style="display: none;">
			<i class="fa fa-window-close fa-lg"></i> Close
		</button>
	</ul>
</div>

<div class="d-flex justify-content-center" id="flashcards_panel_learn_buttons">
    <div class="p-2">
        <button class="btn flashcards_learn" id="button_flashcards_learn_stopp"><i class="fa fa-stop fa-lg"></i></button>
    </div>
    <div class="p-2">
        <button class="btn flashcards_learn" id="button_flashcards_learn_next"><i class="fa fa-step-forward fa-lg"></i></button>
    </div>
    <div class="p-2">
        <button class="btn flashcards_learn" id="button_flashcards_learn_passed"><i class="fa fa-thumbs-o-up fa-lg"></i></button>
    </div>
    <div class="p-2">
        <button class="btn flashcards_learn" id="button_flashcards_learn_failed"><i class="fa fa-thumbs-o-down fa-lg"></i></button>
    </div>
</div>

<div id="panel_box_attributes" class="panel-collapse collapse">	
	<div class="container-fluid"> 
		<div class="row">
			<div class="col-sm-12">
				<div class="form-group">
					<label for="flashcards_box_title">Title:</label>
					<input class="form-control" id="flashcards_box_title" name="title" required minlength="10" maxlength="60">
					<small class="form-text text-muted">Short descriptive title (between 10 to 60 characters)</small>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-sm-12">
				<div class="form-group">
					<label for="flashcards_box_description">Description:</label>
					<textarea class="form-control" rows="5" id="flashcards_box_description" name="description" required minlength="10" maxlength="800"></textarea>
					<small class="form-text text-muted">Description of box (between 10 to 800 characters)</small>
				</div>
			</div>
		</div>     
		<div class="row" id="flashcards-block-changes-row">
                        <div class="col-sm-12">
                                <label><input type="checkbox" id="flashcards-block-changes"> Only the owner (you) can make changes to the original</label>
                        </div>
		</div>    
		<div class="row">
			<div class="col-sm-12">
				<div class="form-group">
                                        <small id="flashcards_owner" class="form-text text-muted">owner is {{$flashcards_owner}}. </small>
                                        <small id="flashcards_editor" class="form-text text-muted">{{$flashcards_editor}}</small>
				</div>
			</div>
		</div>      
		<div class="row">
			<div class="col-sm-10">
				<button class="btn" data-toggle="collapse" id="flashcards_perms" href="#panel_flashcards_permissions" role="button" aria-expanded="false" aria-controls="panel_flashcards__permissions"><i class="fa fa-community fa-lg"></i> Permissions</button>
			</div>      
			<div class="col-sm-2">
				<button class="btn" data-toggle="collapse" href="#panel_flashbox_settings" role="button" aria-expanded="false" aria-controls="panel_flashbox_settings"><i class="fa fa-cogs fa-lg"></i> Settings</button>
			</div>
		</div>
		<div id="panel_flashbox_settings" class="panel-collapse collapse">
			<div class="row">
				<div class="col-sm-12">
					<hr/>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<h4>Settings</h4>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<hr/>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-10">
					Restore all settings below to default values
				</div>
				<div class="col-sm-2">
					<button class="btn" id="button_flashcards_settings_default"><i class="fa fa-mail-reply fa-lg"></i> Restore</button>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<hr/>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<label><input type="checkbox" id="flashcards-autosave"> Automatically upload changes (sync) </label>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<label><input type="checkbox" id="flashcards-convenient-search"> Convenient search </label>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<label><input type="checkbox" id="flashcards-card-sort"> Show sortation </label>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<hr/>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<h4>How to learn</h4>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<hr/>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<label><input type="checkbox" id="flashcards-switch-learn-directions"> Switch learn direction (sides of cards) </label>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<label><input type="checkbox" id="flashcards-default-sort"> Always start to learn with first box (Leitner default)</label>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<label><input type="checkbox" id="flashcards-switch-learn-all"> Learn all displayed cards no matter wether due to learn or not </label>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<hr/>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<h4>Adapt the Learn System</h4>
                                        <br>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12" id="flashcards-learn-system-visualisation"></div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<hr/>
					You can refine the <a href="https://en.wikipedia.org/wiki/Leitner_system" target="_blank">Leitner System</a> by setting the variables below.
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">					
					<div class="form-group">
						<label for="flashcards-learn-system-decks"><br>Number of Decks</label>
						<input type="number" class="form-control flashcards-learn-params" id="flashcards-learn-system-decks" placeholder="7" min="4" max="10">
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">					
					<div class="form-group">
						<label for="flashcards-learn-system-deck-repetitions">Repetitions per deck (classic Leitner is "1")</label>
						<input type="number" class="form-control flashcards-learn-params" id="flashcards-learn-system-deck-repetitions" placeholder="3" min="1" max="10">
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">					
					<div class="form-group">
						<label for="flashcards-learn-system-exponent">Exponent to calculate the wait time inside a deck... <span id="fc_leitner_calculation"></span></label>
						<input type="number" class="form-control flashcards-learn-params" id="flashcards-learn-system-exponent" placeholder="3" min="1" max="5">
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<hr/>
					<h4>Visibility of Card Details</h4>
					...in the 'big' table<br><br>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="0"> card - id = creation time</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="1"> card - side 1</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="2"> card - side 2</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="3"> card - description</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="4"> card - tags</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="5"> card - last modified</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="6"> learn progress - deck</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="7"> learn progress - status inside deck</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="8"> learn progress - how often learned</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="9"> learn progress - time last learnt</label>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-12">
					<div class="checkbox">
						<label><input type="checkbox" class="flashcards-column-visibility" col="10"> has local changes for upload</label>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<div id="panel_flashcards_card" class="panel-collapse collapse">
	<div class="panel panel-default">
		<div class="panel-heading flashcards_nav" id="flashcards_panel_card_header">
		    <h2 class="panel-title">
				Card
				<button class="btn" id="flashcards_cardedit_save"><i class="fa fa-save fa-lg"></i></button>	
				<button class="btn" id="flashcards_cardedit_cancel"><i class="fa fa-window-close"></i></button>
		    </h2>
		</div>      
		<div id="flashcards_main_card">        
            <div class="container-fluid"> 
              <div class="row">
                <div class="col-sm-6">
                     <div class="form-group">
                      <label for="flashcards_language1">Side 1:</label>
                      <textarea class="form-control card-content" rows="5" id="flashcards_language1"></textarea>
                    </div> 
                </div>
                <div class="col-sm-6">
                     <div class="form-group">
                      <label for="flashcards_language2">Side 2:</label>
                      <textarea class="form-control card-content" rows="5" id="flashcards_language2"></textarea>
                    </div>
                </div>
              </div>
              <div class="row">
                <div class="col-sm-12">
                     <div class="form-group">
                      <label for="flashcards_description">Description:</label>
                      <textarea class="form-control card-content" rows="5" id="flashcards_description"></textarea>
                    </div>
                </div>
              </div>
              <div class="row">
                <div class="col-sm-12">
                    <div class="form-group">
                      <label for="flashcards_tags">Tags:</label>
                      <input class="form-control card-content" id="flashcards_tags">
                    </div>
                </div>
              </div>
              <div class="row">
                <div class="col-sm-12">
                     <small class="form-text text-muted" id="flashcard_learn_card_details"></small>	
                </div>
              </div>
            </div>
		</div>
	</div>
</div>
                        
<div id="panel_flashcards_permissions" class="panel-collapse collapse">
</div>
                        
<div id="panel_flashcards_cards_actions" style="display: none;">
	<span class="navbar-brand">
            <div class="container-fluid"> 
              <div class="row">
                <div class="col-sm-12">
                    <div class="form-group">
                        <button class="nav-item btn btn-default" id="button_flashcards_search_cards" style="display: none;">
                            <i class="fa fa-search"></i>
                        </button>
                        <button class="nav-item btn btn-default" id="button_flashcards_new_card">
                            <i class="fa fa-calendar-plus-o"></i>
                        </button>
                        <span id="span_flashcards_cards_actions_status"></span>
                        <span>Cards</span>
                    </div>
                </div>
              </div>
              <div class="row">
                <div class="col-sm-12">
                    <div class="form-group">
                        <input id="input_flashcards_search_cards" style="display: none;">
                    </div>
                </div>
              </div>
            </div>
	</span>
</div>

<div id="panel_flashcards_cards" style="display: none;"></div>

<div id="panel_cloud_boxes_1" style="display: none;">
    <div id="panel_cloud_boxes_header"></div>
    <div id="panel_cloud_boxes_content"></div>
</div>

<div id="panel_search_cloud_boxes" style="display: none;"></div>

<div id="panel_flashcards_help" style="display: none;">
	<div class="container-fluid">
            <div class="row">
                <div class="col-sm-12">
                    <p>Flashcards version <span id="flashcards_version">{{$flashcards_version}}</span></p>
                    <p>This addon is a  <a href="https://en.wikipedia.org/wiki/List_of_flashcard_software" target="_blank">flashcard software</a> that uses <a href="https://en.wikipedia.org/wiki/Spaced_repetition" target="_blank">spaced repetition</a> as a learning technique.</p>
                    <p><img src="/addon/flashcards/view/img/leitner-system.png" align="center" width="70%"></p>
                    <p>You can share the flash cards with other users.</p>
                    <p>Your learning progress will be kept private.</p>
                    <hr/>
                    <h4>The School Example</h4>
                    <hr/>
                    <h4>The school...</h4>
                    <p>has the addon Flashcards installed and creates a box of flashcards, let's say "English-Italian".</p>
                    <p>The school has full control of who is allowed to</p>
                    <ul>
                        <li>open and learn with "English-Italian"</li>
                        <li>make changes to "English-Italian"</li>
                    </ul>
                    <h4>The student...</h4>
                    <ul>
                        <li>can access "English-Italian" via menu -> "List Boxes"</li>
                        <li>can make changes to "English-Italian" if he is allowed to to so</li>
                        <li>can change the learning algorithm, e.g. how often to repeat cards,...</li>
                        <li>use the cards as dictionary</li>
                    </ul>
                    <hr/>
                    <h4>FAQs</h4>
                    <hr/>
                    <h4>How to add or change cards?</h4>
                    <p>By default the school and the students can both add or modify cards. The syncronization is done automatically as soon as they upload (save) changes.</p>
                    <h4>Permissions and Technically</h4>
                    <p>A student sees those flashcards only the school allows him to see. The student will get a copy of "English-Italian". For both users it looks the same, same URL, same content. Everything is done under the hood. The student does not own the flashcards. The school can withdraw the permissions for a student or even delete the flashcards at any time.</p>
                    <h4>Federation</h4>
                    <p>Users of Hubzilla and ZAP (both talk ZOT) will be able to use flashcards on other servers, e.g. "English-Italian" of the school in our example.</p>
                    <p><a href="https://en.wikipedia.org/wiki/ActivityPub" target="_blank">ActivityPub</a>: It is not tested yet but should be available in the near future. If enabled a student will be able to use the addon without having an account at Hubzulla or ZAP as long as the account supports <a href="https://en.wikipedia.org/wiki/ActivityPub" target="_blank">ActivityPub</a>. Users of Mastodon should have ActivityPub for example. Stay tuned.</p>
                </div>
            </div>
	</div>
</div>




<div id="panel_flashcards_cards" style="display: none;"></div>

<div id="flashcards_post_url" style="display: none;">{{$post_url}}</div>
<div id="flashcards_nick" style="display: none;">{{$nick}}</div>
<div id="flashcards_is_owner" style="display: none;">{{$is_owner}}</div>
<div id="flashcards_is_local_channel" style="display: none;">{{$is_local_channel}}</div>
<div id="flashcards_is_allowed_to_create_box" style="display: none;">{{$is_allowed_to_create_box}}</div>
<!--
<p>
	<button class="btn" id="run_unit_tests"">Test</button>
</p>
-->
				


<!-- 
Modal to delete a box 
-->
<div class="modal fade" id="delete_box_modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
			    <h4 class="modal-title" id="exampleModalLabel">Delete Box</h4>
			    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
			      <span aria-hidden="true">&times;</span>
			    </button>
			</div>
			<div class="modal-body" id="modal_body_delete_box">
			    Are you sure to delete this box.
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
			    <button type="button" class="btn btn-primary btn-danger" id="button_delete_box" boxid="notset">Delete</button>
			</div>
	    </div>
	</div>
</div>
<div id="acl_modal_flashcards_cards"></div>


<script src="/addon/flashcards/view/js/flashcards.js"></script>
