var logger = function () {

    var oldConsoleLog = null;
    var pub = {};

    pub.enableLogger = function enableLogger() {
        if (oldConsoleLog == null)
            return;
        window['console']['log'] = oldConsoleLog;
    };

    pub.disableLogger = function disableLogger() {
        oldConsoleLog = console.log;
        window['console']['log'] = function () {
        };
    };

    pub.log = function log(message) {
        var now = new Date();
        var now = new Date();
        console.log(now.toISOString() + " - " + message);
    }

    return pub;

}();

class BoxLocalStore {
    check() {
        logger.log("BoxLocalStore - check local storage...");
        try {
            var firstCheck = 'localStorage' in window;
            var secondCheck = window['localStorage'] !== null;
            localStorage.setItem("testkey", "value");
            this.isLocalStorageSupported = firstCheck && secondCheck;
            logger.log("BoxLocalStore - local storage is available: " + this.isLocalStorageSupported);
        } catch (e) {
            this.isLocalStorageSupported = false;
            logger.log("BoxLocalStore - local storage is NOT available");
        }
    }
    isAvailable() {
        logger.log("BoxLocalStore - local storage available? " + this.isLocalStorageSupported);
        return this.isLocalStorageSupported
    }
    getItem(id) {
        var value = "";
        if (this.isLocalStorageSupported) {
            try {
                value = localStorage.getItem(id);
            } catch (e) {
                this.isLocalStorageSupported = false;
                logger.log("BoxLocalStore - local storage is NOT available. It might be the user changed the browser settings after starting the app.");
            }
        }
        if (!value) {
            value = "";
        }
        //logger.log("BoxLocalStore - returning item id = " + id + ", value = " + value);
        return value;
    }
    setItem(id, value) {
        //logger.log("BoxLocalStore - storing item id = " + id + ", value = " + value);
        if (this.isLocalStorageSupported) {
            try {
                localStorage.setItem(id, value);
            } catch (e) {
                this.isLocalStorageSupported = false;
                logger.log("BoxLocalStore - local storage is NOT available. It might be the user changed the browser settings after starting the app.");
            }
        }
    }
}

var postUrl = '';
var is_owner = '';
var flashcards_editor = '';
var is_local_channel = '';
var is_allowed_to_create_box = '';

var boxLocalStore = new BoxLocalStore();
var stringContentOldCard = '';
/*
 * The card stores its data in an array in the form
 * 
 * index - value
 * 
 * 0 - id = creation timestamp, milliseconds, Integer
 * 1 - Language A, String
 * 2 - Language B, String
 * 3 - Description, String
 * 4 - Tags, "Lesson 010.03" or anything else, String
 * 5 - last modified content, Integer
 * 6 - Deck, Integer from 0 to 6 but configurable
 * 7 - progress in deck default 0, Integer
 * 8 - How often learned (information for the user only), Integer
 * 9 - last modified progress, milliseconds as Integer
 * 10 - has local changes, Boolean 
 */
class Card {
    constructor() {
        // content of a box
        this.content = [new Date().getTime(), "", "", "", "", 0, 0, 0, 0, 0, false, false];
    }
    validate() {
        // id = Integer = ms = creation time of card
        this.checkCardInteger(0);
        // language A = String
        this.checkCardField(1);
        // language B = String
        this.checkCardField(2);
        // description = String
        this.checkCardField(3);
        // tags = String
        this.checkCardField(4);
        // last modified content = ms = Integer >= 0
        this.checkCardInteger(5);
        // deck = Integer from 0 to 6
        this.checkCardInteger(6);
        if (this.content[6] > box.content.cardsDecks - 1) {
            this.content[6] = box.content.cardsDecks - 1;
        }
        // progress in deck = Integer from 0 to 2
        this.checkCardInteger(7);
        if (this.content[7] > box.content.cardsRepetitionsPerDeck - 1) {
            this.content[7] = box.content.cardsRepetitionsPerDeck - 1;
        }
        // learn count = Integer >= 0
        this.checkCardInteger(8);
        // last modified progress = ms = Integer >= 0
        this.checkCardInteger(9);
    }
    checkCardField(index) {
        var value = this.content[index]
        if (typeof value === 'string' || value instanceof String) {
            var maxLength = box.content.maxLengthCardField;
            if (this.content[index].length > maxLength) {
                this.content[index] = this.content[1].substring(0, maxLength);
            }
        } else {
            this.content[1] = "";
        }
    }
    checkCardInteger(index) {
        var value = this.content[index];
        if (Number.isInteger(value)) {
            if (value < 0) {
                value = 0;
            }
        } else {
            var i = parseInt(value);
            if (Number.isInteger(i)) {
                if (i < 0) {
                    value = 0;
                } else {
                    value = i;
                }
            } else {
                value = 0;
            }
        }
        this.content[index] = value;
    }
    isDue(now) { // is due to learn
        this.validate();
        var deck = this.content[6];
        var deckProgress = this.content[7];
        if (deck == 0) {
            return true;
        }
        var daysToWait = Math.pow(box.content.cardsDeckWaitExponent, deck - 1);
//		var repetitionsPerDeck = box.content.cardsRepetitionsPerDeck;	
        var daysToWaitInDeck = Math.pow(box.content.cardsDeckWaitExponent, deck - 2);
        daysToWaitInDeck = Math.round(daysToWaitInDeck);
        if (deckProgress > 0) {
            if (daysToWaitInDeck < 1) {
                return true;
            }
            daysToWait = daysToWaitInDeck;
//			if(daysToWait >= repetitionsPerDeck) {
//				daysToWait = 1;
//			} else {
//				return true; // daysToWait = 0;
//			}
        }
        var lastLearned = new Date(this.content[9]); // milliseconds
        var logMessage = 'Card id ' + this.content[0] + ' last learned  ' + lastLearned.toLocaleString();
        // The next sets the UTC time to 00:00:00.000 of the LOCAL day
        // automatically. This means that after setHours(0,0,0,0)...
        // ... d.getTime() will return the begin of the local day
        lastLearned.setHours(0, 0, 0, 0);
        var aDay = 1000 * 60 * 60 * 24;
        var waitTime = daysToWait * aDay;
        if (!now) {
            now = new Date().getTime();
        }
        var dueMilliseconds = (lastLearned.getTime() + waitTime);
        logMessage += '. Due to learn in ' + daysToWait + ' days after ' + lastLearned.toLocaleString();
        logMessage += ' (UTC' + lastLearned.toISOString();
        logMessage += ') => due at ' + new Date(dueMilliseconds).toLocaleString();
        logMessage += ', now is ' + new Date(now).toLocaleString();
        if (now > dueMilliseconds) {
            //logger.log('Yes, card is due to learn. ' + logMessage);
            return true;
        }
        //logger.log('No, card is not due to learn. ' + logMessage);
        return false;
    }
    move(passed) { // learn progress
        logger.log('Moving card ' + this.content[0] + ' with deck =  ' + this.content[6] + ' and progress in deck = ' + this.content[7]);
        this.content[8] = this.content[8] + 1; // learn count
        this.content[9] = new Date().getTime(); // milliseconds last learned
        this.content[10] = true;  // please uploaded
        if (!passed) {
            this.content[6] = 0; // move into first deck
            this.content[7] = 0;
            logger.log('Moved card ' + this.content[0] + ' back to deck 0');
            return;
        }
        var deck = this.content[6];
        var deckProgress = this.content[7] + 1;
        if (deckProgress >= box.content.cardsRepetitionsPerDeck) {
            deckProgress = 0;
            if (deck < (box.content.cardsDecks - 1)) {
                deck++;  // move into next deck
            }
        }
        this.content[6] = deck;
        this.content[7] = deckProgress;
        logger.log('Moved card ' + this.content[0] + ' to deck ' + this.content[6] + ' with progress in deck ' + this.content[7]);
    }
    edit() {
        var i;
        stringContentOldCard = "";
        for (i = 1; i < 5; i++) { // learn content only: side 1, side 2, description, tags
            stringContentOldCard += this.content[i];
        }
    }
    save() {
        var i;
        var contentLanguage = "";
        for (i = 1; i < 5; i++) {
            contentLanguage += this.content[i];
        }
        if (stringContentOldCard != contentLanguage) {
            this.content[10] = true; // flag that card has local changes
            this.content[5] = new Date().getTime(); // time stamp for content
            return true;
        }
        return false;
    }
    /* Get the content of the card. Every data is store in an array.
     * 
     * returns Array the content
     */
    getContent() {
        return this.content;
    }
    /*
     * Set the content
     * 
     * param Array the content
     */
    setContent(contentArray) {
        this.content = contentArray;
    }
}

var card = new Card();
var stringContentOldBox = '';
var stringContentOldBoxPrivate = '';
class Box {
    constructor() {
        // key for localstorage
        this.KEY_LOCALSTORAGE_ID = "flashcards.box";
        // content of a box
        this.content = {
            "boxID": "", // = resource_id in Hubzilla DB
            "title": "",
            "description": "",
            "creator": "",
            "creator_xchan_hash": "",
            "lastShared": 0,
            "boxPublicID": 0, // milliseconds created
            "size": 0,
            "lastEditor": "",
            "lastChangedPublicMetaData": 0,
            "maxLengthCardField": 1000,
            "cardsDecks": 7, // this makes 7 decks (0 to 6)
            "cardsDeckWaitExponent": 3,
            "cardsRepetitionsPerDeck": 3, // would be classical Leitner system
            "cardsColumnName": ["Created", "Side 1", "Side 2", "Description", "Tags", "modified", "Deck", "Progress", "Counter", "Learnt", "Upload"],
            "lastChangedPrivateMetaData": 0,
            "private_sortColumn": 0,
            "private_sortReverse": false,
            "private_filter": ["", "", "", "", "", "", "", "", "", "", ""],
            "private_visibleColumns": [false, true, true, false, false, false, false, false, false, false, false],
            "private_switch_learn_direction": false,
            "private_switch_learn_all": false,
            "private_hasChanged": false,
            "private_block": false,
            "private_autosave": true,
            "private_show_card_sort": false,
            "private_sort_default": true,
            "private_search_convenient": true,
            "cards": []
        };
        // not persistant search string for convenient search
        this.search = "";
        this.searchResultColumns = [false, true, true, false, false, false, false, false, false, false, false];
    }
    /**
     * load content from local storage
     */
    load() {
        logger.log("Box - load...");
        if (boxLocalStore.isAvailable()) {
            var contentString = boxLocalStore.getItem(this.KEY_LOCALSTORAGE_ID);
            if (contentString != "") {
                this.content = JSON.parse(contentString)
            }
        } else {
            logger.log("Box - can NOT load from local storage");
        }
    }
    loadFromString(s) {
        logger.log("Box - load from string...");
//		var jsonString = s.replace(/\"/g, '"');
        this.content = JSON.parse(s);
    }
    /**
     * store content to local storage
     */
    store() {
        logger.log("Box - store...");
        if (boxLocalStore.isAvailable()) {
            // public metadata
            // store as String to avoid errors with local storage that might
            // sometimes convert a stored object to a String
            boxLocalStore.setItem(this.KEY_LOCALSTORAGE_ID, JSON.stringify(this.content));
        } else {
            logger.log("Box - can NOT store to local storage");
        }
    }
    isEmpty() {
        this.hasTitle = true;
        if (this.content.title) {
            if (this.title != "") {
                this.hasTitle = false;
            }
        }
        logger.log("Box - is empty: " + this.hasTitle);
        return this.hasTitle;
    }
    setTitle(s) {
        this.content.title = s;
    }
    setDescription(s) {
        this.content.description = s;
    }
    setBoxId(s) {
        this.content.boxID = s;
    }
    getContent() {
        return this.content;
    }
    setContent(newContent) {
        this.content = newContent;
    }
    toString() {
        return JSON.stringify(this.content);
    }
    validate() {
        // id = Integer = ms = creation time of card
        //this.content.boxID = this.checkInteger(this.content.boxID);		
        this.content.title = this.checkString(this.content.title, 80);
        this.content.description = this.checkString(this.content.description, 1000);
        this.content.creator = this.checkString(this.content.creator, 256);
        this.content.creator_xchan_hash = this.checkString(this.content.creator_xchan_hash, 256);
        this.content.lastShared = this.checkInteger(this.content.lastShared);
        this.content.boxPublicID = this.checkString(this.content.boxPublicID, 256);
        this.content.size = this.checkInteger(this.content.size);
        this.content.lastEditor = this.checkString(this.content.lastEditor, 80);
        this.content.lastChangedPublicMetaData = this.checkInteger(this.content.lastChangedPublicMetaData);
        this.content.lastChangedPrivateMetaData = this.checkInteger(this.content.lastChangedPrivateMetaData);
        this.content.maxLengthCardField = this.checkInteger(this.content.maxLengthCardField);
        if (this.content.maxLengthCardField > 1000) {
            this.content.maxLengthCardField = 1000;
        }
        var i = this.checkInteger(this.content.cardsDecks);
        if (i < 4) {
            this.content.cardsDecks = 4;
        }
        if (this.content.cardsDecks > 10) {
            this.content.cardsDecks = 10;
        }
        this.content.cardsDeckWaitExponent = this.checkInteger(this.content.cardsDeckWaitExponent);
        if (this.content.cardsDeckWaitExponent < 1) {
            this.content.cardsDeckWaitExponent = 1;
        }
        if (this.content.cardsDeckWaitExponent > 5) {
            this.content.cardsDeckWaitExponent = 5;
        }
        this.content.cardsRepetitionsPerDeck = this.checkInteger(this.content.cardsRepetitionsPerDeck);
        if (this.content.cardsRepetitionsPerDeck < 1) {
            this.content.cardsRepetitionsPerDeck = 1;
        }
        if (this.content.cardsRepetitionsPerDeck > 10) {
            this.content.cardsRepetitionsPerDeck = 10;
        }
        this.content.private_block = this.checkBoolean(this.content.private_block, false);
        this.content.private_sortColumn = this.checkInteger(this.content.private_sortColumn);
        if (this.content.private_sortColumn > 9) {
            this.content.private_sortColumn = 9;
        }
        this.content.private_sortReverse = this.checkBoolean(this.content.private_sortReverse, false);
        this.content.private_switch_learn_direction = this.checkBoolean(this.content.private_switch_learn_direction, false);
        this.content.private_switch_learn_all = this.checkBoolean(this.content.private_switch_learn_all, false);
        this.content.private_autosave = this.checkBoolean(this.content.private_autosave, true);
        this.content.private_show_card_sort = this.checkBoolean(this.content.private_show_card_sort, false);
        this.content.private_sort_default = this.checkBoolean(this.content.private_sort_default, true);
        this.content.private_search_convenient = this.checkBoolean(this.content.private_search_convenient, true);
        var i;
        for (i = 0; i < this.content.private_visibleColumns.length; i++) {
            var defaultBool = false;
            if (i === 1 || i === 2) {
                defaultBool = true;
            }
            this.content.private_visibleColumns[i] = this.checkBoolean(this.content.private_visibleColumns[i], defaultBool);
        }

        if (this.content.cards == null) {
            this.content.cards = [];
            return;
        }
        for (i = 0; i < this.content.cards.length; i++) {
            var valCard = new Card();
            valCard.content = this.content.cards[i].content;
            valCard.validate();
        }
    }
    checkBoolean(b, defaultBool) {
        if (typeof (b) === "boolean") {
            return b;
        }
        if (b === "true") {
            return true;
        }
        if (b === "false") {
            return false;
        }
        return defaultBool;
    }
    checkString(s, maxLength) {
        if (typeof s === 'string' || s instanceof String) {
            if (s.length > maxLength) {
                return s.substring(0, maxLength);
            }
        } else {
            return "";
        }
        return s;
    }
    checkInteger(value) {
        if (Number.isInteger(value)) {
            if (value < 0) {
                return 0;
            }
        } else {
            var i = parseInt(value)
            if (Number.isInteger(i)) {
                if (i < 0) {
                    return 0;
                }
                return i;
            } else {
                return 0;
            }
        }
        return value;
    }
    getChangeString() {
        var s = this.content.title;
        s += this.content.description;
        // s += this.content.size;
        // "creator":"",
        // "lastShared":0,
        // "boxPublicID":0, 
        // "lastEditor":"",
        // "lastChangedPublicMetaData":0,
        // "maxLengthCardField":1000,
        // s += this.content.cardsDecks;
        // s += this.content.cardsDeckWaitExponent;
        // s += this.content.cardsRepetitionsPerDeck;
        // "cards":[],
        // "private_sortColumn":0,
        // "private_sortReverse":false
        return s;
    }
    getChangeStringPrivate() {
        var s = this.content.cardsDecks;
        s += this.content.cardsDeckWaitExponent;
        s += this.content.cardsRepetitionsPerDeck;
        s += this.content.private_sortColumn;
        s += this.content.private_sortReverse;
        s += this.content.private_filter;
        s += this.content.private_visibleColumns;
        s += this.content.private_switch_learn_direction;
        s += this.content.private_switch_learn_all;
        s += this.content.private_autosave;
        s += this.content.private_show_card_sort;
        s += this.content.private_sort_default;
        s += this.content.private_search_convenient;
        s += this.content.private_block;
        return s;
    }
    edit() {
        stringContentOldBox = this.getChangeString();
        stringContentOldBoxPrivate = this.getChangeStringPrivate();
    }
    save(action) {
        logger.log('box.save(): Save changes of box if any....');
        if (action == 'box') {
            var s = this.getChangeString();
            if (stringContentOldBox != s) {
                this.content.lastChangedPublicMetaData = new Date().getTime();
                this.content.private_hasChanged = true;
                this.content.lastEditor = flashcards_editor;
                logger.log('box.save(): public metadata of box has changed');
            }
            s = this.getChangeStringPrivate();
            if (stringContentOldBoxPrivate != s) {
                this.content.lastChangedPrivateMetaData = new Date().getTime();
                this.content.private_hasChanged = true;
                logger.log('box.save(): private metadata of box has changed');
            }
        } else if (action == 'card') {
            this.content.private_hasChanged = true;
            if (this.content.size !== this.content.cards.length) {
                this.content.lastChangedPublicMetaData = new Date().getTime();
                this.content.size = this.content.cards.length;
            }
            this.content.lastEditor = flashcards_editor;
            logger.log('box.save(): card content has changed');
        } else if (action == 'progress') {
            logger.log('box.save(): card progress has changed');
        } else {
            logger.log('box.save(): You should never see this line.');
            return;
        }
        this.store();
    }
    hasChanges() {
        return this.content.private_hasChanged;
    }
    comparator(a, b) {
        var aValue = a.content[sortByColumn];
        var bValue = b.content[sortByColumn];
        if (sortByColumn == 1 || sortByColumn == 2 || sortByColumn == 3 || sortByColumn == 4) {
            aValue = a.content[sortByColumn].toLocaleLowerCase();
            bValue = b.content[sortByColumn].toLocaleLowerCase();
        }
        if (!sortReversOrder) {
            if (aValue < bValue)
                return -1;
            if (aValue > bValue)
                return 1;
            return 0;
        } else {
            if (aValue > bValue)
                return -1;
            if (aValue < bValue)
                return 1;
            return 0;
        }
    }
    sortBy(index, reverse) {
        logger.log('Sorting cards by column = ' + index + ', reverse order = ' + reverse + '...');
        sortByColumn = index; // TODO Check if it is possible to use this.private_sortColumn inside comparator(a, b)
        sortReversOrder = reverse; // TODO Check if it is possible to use this.private_sortColumn inside comparator(a, b)
        this.content.private_sortColumn = index;
        this.content.private_sortReverse = reverse;
        var ret = this.content.cards.sort(this.comparator);
        return ret;
    }
    sort() {
        if (!this.content.cards) {
            return;
        }
        this.sortBy(this.content.private_sortColumn, this.content.private_sortReverse);
    }
    getCardsArrayFiltered(filterArray) {
        var filtered = [];
        if(this.search !== "") {
            logger.log('Using convenient search with search string = ' + this.search + '...');
            this.searchResultColumns = [false, true, true, false, false, false, false, false, false, false, false];
            var parts = this.search.split(" ");
            var partsFound = new Array(parts.length);
            for (i = 0; i < this.content.cards.length; i++) {
                for(var z = 0; z < parts.length; z++) {
                    partsFound[z] = false;
                }
                var card = this.content.cards[i];
                var j;
                for(j = 1; j < 5; j++) {
                    var text = card.content[j];
                    if(text.length < 1) {
                        continue;
                    }
                    // String search: make a search with AND for every word in a search string
                    var k;
                    for (k = 0; k < parts.length; k++) {
                        var value = parts[k].trim();
                        if (value === "") {
                            continue;
                        }
                        if (text.toString().toLocaleLowerCase().includes(value.toLocaleLowerCase())) {
                            partsFound[k] = true;
                            this.searchResultColumns[j] = true;
                        }
                    }
                }
                var notFound = false;
                for(var z = 0; z < parts.length; z++) {
                    if(! partsFound[z]) {
                        notFound = true;
                    }
                }
                if(! notFound) {
                    filtered.push(card);
                }
            }
        } else {            
            if (filterArray) {
                this.content.private_filter = filterArray;
            } else {
                filterArray = this.content.private_filter;
            }
            logger.log('Filter cards array with filter array = ' + filterArray + '...');
            this.searchResultColumns = [false, false, false, false, false, false, false, false, false, false, false];
            // Is filter empty?
            var l;
            var isFilterEmpty = true;
            for (l = 0; l < filterArray.length; l++) {
                if (filterArray[l].trim() != "") {
                    isFilterEmpty = false
                }
            }
            if (isFilterEmpty) {
                return this.content.cards;
            }
            // iterate all cards
            var i;
            for (i = 0; i < this.content.cards.length; i++) {
                var card = this.content.cards[i];
                var aWordFound = false;
                var aWordNotFound = false;
                // iterate columns
                var j;
                for (j = 0; j < filterArray.length; j++) {
                    if (aWordNotFound) {
                        break;
                    }
                    if (filterArray[j] == "") {
                        // ignore if a column has no search string
                        continue;
                    }

                    if (j == 0 || j == 5 || j == 9) {
                        // Date-Time Search
                        if (card.content[j] != 0) { // ignore if time is 0 = 1970-01-01
                            var localTimeString = new Date(card.content[j]).toLocaleString();
                            var value = filterArray[j];
                            if (localTimeString.toLocaleLowerCase().includes(value.toLocaleLowerCase())) {
                                aWordFound = true;
                            } else {
                                aWordNotFound = true;
                                break;
                            }
                        }
                    } else {
                        // String search: make a search with AND for every word in a search string
                        var parts = filterArray[j].split(" ");
                        var k;
                        for (k = 0; k < parts.length; k++) {
                            var value = parts[k].trim();
                            if (value == "") {
                                continue;
                            }
                            if (!card.content[j].toString().toLocaleLowerCase().includes(value.toLocaleLowerCase())) {
                                aWordNotFound = true;
                                break;
                            } else {
                                aWordFound = true;
                            }
                        }
                    }
                }
                if (aWordFound && !aWordNotFound) {
                    filtered.push(card);
                }
            }
        }
        return filtered;
    }
    getCopy() {
        var deepCopy = new Box();
        deepCopy.setContent(JSON.parse(JSON.stringify(this.content)));
        return deepCopy;
    }
    getContentToUpload() {
        logger.log("Create a copy a box for upload...");
        if (this.content.lastShared < 1) {
            logger.log("This box was never uploaded > Return full copy.");
            var boxToUpload = this.getCopy();
            boxToUpload.setAllUploadMarkers();
            return boxToUpload;
        }
        var boxToUpload = this.getCopy();
        if (boxToUpload.content.boxID == "") {
            logger.log('Box has no boxID. This means it does not exist in the DB. Return the whole box...')
            return boxToUpload;
        }
        logger.log('Remove cards from copied box to upload only cards that have changed...');
        var cardsToUpload = [];
        boxToUpload.content.cards = cardsToUpload;
        var i;
        for (i = 0; i < this.content.cards.length; i++) {
            if (this.content.cards[i].content[10]) { // changed cards only
                cardsToUpload.push(this.content.cards[i]);
            }
        }
        logger.log('The copied box contains ' + cardsToUpload.length + ' cards that have changed since the last upload (sync).');
        return boxToUpload;
    }
    merge(boxRemote) {
        logger.log('Start to merge with a remote box...');
        boxRemote.validate();
        if (this.content.boxPublicID != boxRemote.content.boxPublicID) {
            logger.log('Public IDs not equal. Local = ' + this.content.boxPublicID + ', remote' + boxRemote.content.boxPublicID + '. No merge.');
            return;
        }
        var isOwnBox = false;
        if (this.content.boxID == boxRemote.content.boxID) {
            isOwnBox = true; // TODO Change this to take the owner or ... depending on implementation of how it is shared in Hubzill
        }
        var remotePublicMetaDataWins = false;
        var remotePrivateMetaDataWins = false;
        var keysPublic = ['title', 'description', 'lastEditor', 'lastChangedPublicMetaData'];
        if (this.content.lastChangedPublicMetaData !== boxRemote.content.lastChangedPublicMetaData) {
            var i;
            for (i = 0; i < keysPublic.length; i++) {
                if (this.content.lastChangedPublicMetaData < boxRemote.content.lastChangedPublicMetaData) {
                    this.content[keysPublic[i]] = boxRemote.content[keysPublic[i]];
                    remotePublicMetaDataWins = true;
                    logger.log('remote box overwrites box public data: key = ' + keysPublic[i]);
                }
            }
        }
        if (isOwnBox) {
            var keysPrivate = ['cardsDecks', 'cardsDeckWaitExponent', 'cardsRepetitionsPerDeck', 'private_block', 'private_sortColumn', 'private_sortReverse', 'private_filter', 'private_visibleColumns', 'private_switch_learn_direction', 'private_switch_learn_all', 'private_autosave', 'private_show_card_sort', 'private_sort_default', 'private_search_convenient', 'lastChangedPrivateMetaData'];
            if (this.content.lastChangedPrivateMetaData !== boxRemote.content.lastChangedPrivateMetaData) {
                var i;
                for (i = 0; i < keysPrivate.length; i++) {
                    if (this.content.lastChangedPrivateMetaData < boxRemote.content.lastChangedPrivateMetaData) {
                        this.content[keysPrivate[i]] = boxRemote.content[keysPrivate[i]];
                        remotePrivateMetaDataWins = true;
                        logger.log('remote box overwrites box private data: key = ' + keysPrivate[i]);
                    }
                }
            }
        }
        if (remotePublicMetaDataWins && remotePrivateMetaDataWins) {
            // this is for the "share" button and means box meta data to upload
            this.content.private_hasChanged = false;
        }
        var cardsLocal = this.content.cards;
        var cardsRemote = boxRemote.content.cards;
        if (!cardsLocal) {
            logger.log('No local cards. Try to take remote cards if any.');
            if (!cardsRemote) {
                logger.log('No remote cards.');
            } else {
                logger.log('Take remote cards.');
                this.content.cards = boxRemote.content.cards;
            }
            return;
        }
        if (!cardsRemote) {
            logger.log('No remote cards > no cards to merge');
            return;
        }
        var i;
        for (i = 0; i < cardsRemote.length; i++) {
            var remoteArray = cardsRemote[i].content;
            var localCard = this.getCard(remoteArray[0]);
            if (localCard) {
                var remoteContentWins = false;
                var remoteProgressWins = false;
                var localArray = localCard.content;
                if (localArray[5] < remoteArray[5]) { // last modified content
                    var j;
                    for (j = 1; j < 6; j++) {
                        localArray[j] = remoteArray[j];
                    }
                    remoteContentWins = true;
                    logger.log('The content of card "' + remoteArray[0] + '" was imported from the remote card that was changed at ' + new Date(remoteArray[5]).toLocaleString());
                }
                if (isOwnBox) {
                    if (localArray[9] < remoteArray[9]) { // last modified progress
                        var j;
                        for (j = 6; j < 10; j++) {
                            localArray[j] = remoteArray[j]
                        }
                        remoteProgressWins = true;
                        logger.log('The learn progress of card "' + remoteArray[0] + '" was imported from the remote card that was changed at ' + new Date(remoteArray[5]).toLocaleString());
                    }
                }
                if (remoteContentWins && remoteProgressWins) {
                    localArray[10] = false;
                }
            } else {
                if (!isOwnBox) {
                    var j;
                    for (j = 6; j < 10; j++) {
                        remoteArray[j] = 0;
                    }
                    remoteArray[10] = false;
                    logger.log('The learn progress of card "' + remoteArray[0] + '" was removed from the remote card that was changed at ' + new Date(remoteArray[5]).toLocaleString());
                }
                cardsLocal.push(cardsRemote[i]); // new card
                logger.log('Added the remote card "' + remoteArray[0] + '" changed at ' + new Date(remoteArray[5]).toLocaleString());
            }
        }
    }
    getCard(id) {
        var rCard;
        var i;
        for (i = 0; i < this.content.cards.length; i++) {
            var cId = this.content.cards[i].content[0];
            if (cId == id) {
                rCard = this.content.cards[i];
            }
        }
        return rCard;
    }
    setAllUploadMarkers() {
        this.content.private_hasChanged = true;
        var i;
        for (i = 0; i < this.content.cards.length; i++) {
            this.content.cards[i].content[10] = true;
        }
    }
    removeAllUploadMarkers() {
        this.content.private_hasChanged = false;
        if (!this.content.cards) {
            return;
        }
        var i;
        for (i = 0; i < this.content.cards.length; i++) {
            this.content.cards[i].content[10] = false;
        }
    }
    checkUploadMarkers(cardIDsUploaded) {
        logger.log("Checking upload markers...");
        if (!cardIDsUploaded) {
            logger.log("Upload markers are empty. Return...");
            return;
        }
        logger.log("Checking upload markers.... card id's uploaded = " + cardIDsUploaded)
        box.content.private_hasChanged = false;
        // This function is used to check if all cards of a new box where uploaded.
        // Not all cards are uploaded by a post if there are many cards.
        // During tests the limit was 87 cards.
        var i;
        for (i = 0; i < this.content.cards.length; i++) {
            var j;
            for (j = 0; j < cardIDsUploaded.length; j++) {
                if (this.content.cards[i].content[0] == cardIDsUploaded[j]) {
                    // This card was uploaded AND stored in the DB. Mark as uploaded.
                    this.content.cards[i].content[10] = false;
                    break;
                }
            }
        }
    }

    /**
     * Creates a html table that visualizes the learn system.
     * @param int number of decks
     * @param int exponent used to calculate a wait time inside  deck
     * @param int repetions inside a deck before progressing to next deck
     * @returns Box new Box with the parameters to play
     * @returns String html table
     */
    visualize(count, exponent, repetitions) {
        if (!count) {
            count = this.content.cardsDecks;
        }
        if (!exponent) {
            exponent = this.content.cardsDeckWaitExponent;
        }
        if (!repetitions) {
            repetitions = this.content.cardsRepetitionsPerDeck;
        }
        var boxVisualized = new Box();
        boxVisualized.content.cardsDecks = count;
        boxVisualized.content.cardsDeckWaitExponent = exponent;
        boxVisualized.content.cardsRepetitionsPerDeck = repetitions;
        boxVisualized.validate();
        count = boxVisualized.content.cardsDecks;
        exponent = boxVisualized.content.cardsDeckWaitExponent;
        repetitions = boxVisualized.content.cardsRepetitionsPerDeck;
        var howOftenLearned = 0;
        var table = '<table class="table">';
        table += "<tr>";
        var c;
        for (c = 0; c < count; c++) {
            if (c == 0) {
                table += '<th>Deck</th>';
            }
            table += '<th>' + c + '</th>';
        }
        table += "</tr>";
        table += "<tr>";
        var daysToWait = 0;
        var daysToPassAllDecks = 0;
        for (c = 0; c < count; c++) {
            if (c == 0) {
                table += '<td>[days]</td>';
            }
            howOftenLearned++;
            table += '<td>';
            if (r > 0) {
                daysToWait = Math.pow(exponent, c - 1);
            }
            daysToPassAllDecks += daysToWait;
            table += daysToWait + ' >';
            var daysToWaitInDeck = Math.pow(exponent, c - 2);
            daysToWaitInDeck = Math.round(daysToWaitInDeck);
            var r;
            for (r = 1; r < repetitions; r++) {
                howOftenLearned++;
                table += " " + daysToWaitInDeck + ">";
                daysToPassAllDecks += daysToWaitInDeck;
            }
            table += "</td>";
        }
        table += "</tr>";
        table += "</table>";
        table += '<p>A card needs ' + daysToPassAllDecks + ' days and ' + howOftenLearned + ' repetitions to pass all decks.</p>'
        var calculation = '(deck-1)<sup>exponent</sup>'
        calculation += ' = (' + (count - 1) + '-1)<sup>' + exponent + '</sup>'
        calculation += ' = ' + daysToWait + ' days';
        return [boxVisualized, table, calculation];
    }
}
var box = new Box();
var nick = "";

var sortByColumn = 0;
var sortReversOrder = false;
var timezoneOffsetMilliseconds = 0;

var blockEditBox = false;

function setShareButton() {
    var hasUploads = 0;
    $("#button_share_box").css({'color': ''});
    if (box.hasChanges()) {
        $("#button_share_box").css({'color': 'red'});
        hasUploads = 1;
    }
    var counter = 0;
    if (box.content.cards) {
        var i;
        for (i = 0; i < box.content.cards.length; i++) {
            if (box.content.cards[i].content[10]) {
                counter++;
            }
        }
    }
    if (counter == 0) {
        logger.log('Share button: no card has changes');
        $("#button_share_box_counter").html("");
    } else {
        logger.log('Share button: ' + counter + ' card with changes');
        $("#button_share_box_counter").html('<sup>' + counter + '</sup>');
        $("#button_share_box").css({'color': 'red'});
        hasUploads = 1;
    }
    $("#button_share_box").show();
    return hasUploads;
}

function loadStartPage() {
    if(is_allowed_to_create_box || !box.isEmpty()) {
        fillInputsSettings();
        conductGUIelements('start');
    } else {
        conductGUIelements('show-help');
    }
}

function conductGUIelements(action) {
    var hasUploads = 0;
    if (action === 'learn-next' || action == 'learn-show-other-side') {
        $("#button_share_box").hide();
    } else {
        fillInputsBox();
        hasUploads = setShareButton();
    }
    $("#button_flashcards_close").hide();
    if (box.isEmpty()) {
        $("#flashcards_edit_box").hide();
    } else {
        $("#flashcards_edit_box").show();
    }
    if (action === 'start') {
        $("#panel_box_navigation").show();
        $(".flashcards_nav").show();
        $(".flashcards_learn").hide();
        $("#panel_flashcards_card").hide();
        // $("#panel_flashcards_card").collapse("hide");
        $("#flashcards_panel_learn_buttons").hide();
        $("#flashcards_import").prop("disabled", false);
        $("#panel_cloud_boxes_1").hide();
        $("#panel_search_cloud_boxes").hide();
        $('#panel_flashcards_permissions').collapse("hide");
        if (box.isEmpty()) {
            $("#button_flashcards_save_box").show();
            $("#button_flashcards_learn_play").hide();
            $("#button_share_box").hide();
            $('#panel_box_attributes').collapse("show");
            showACLbutton();
            $("#panel_flashcards_cards_actions").hide();
            $("#panel_flashcards_cards").hide();
            $("#button_flashcards_new_card").css({'color': 'green'});
        } else {
            $("#button_flashcards_save_box").hide();
            $("#button_flashcards_learn_play").hide();
            $('#panel_box_attributes').collapse("hide");
            $("#panel_flashcards_cards_actions").show();
            $("#panel_flashcards_cards").show();
            showCards(false);
        }
    }
    if (action === 'edit-box') {
        var localTimeString = new Date(box.content.lastChangedPublicMetaData).toLocaleString();
        $("#flashcards_editor").html("last edit by " + box.content.lastEditor + " at " + localTimeString);
        $('#panel_box_attributes').collapse("show");
        $("#button_share_box").hide();
        $("#button_flashcards_learn_play").hide();
        $("#button_flashcards_save_box").show();
        $("#panel_flashcards_card").hide();
        $("#panel_flashcards_cards_actions").hide();
        $("#panel_flashcards_cards").hide();
        $('#panel_flashcards_permissions').collapse("hide");
        showACLbutton();
    }
    if (action === 'save-box') {
        $("#panel_flashbox_settings").collapse("hide");
        $('#panel_box_attributes').collapse("hide");
        $("#button_flashcards_save_box").hide();
        $("#panel_flashcards_cards_actions").show();
        $("#panel_flashcards_cards").show();
        showCards(true);
    }
    if (action === 'edit-card') {
        $("#flashcards_panel_card_header").show();
        $("#flashcards_cardedit_save").show();
        $("#flashcards_cardedit_cancel").show();
        $('#panel_box_attributes').collapse("hide");
        $("#panel_flashcards_card").show();
        // $("#panel_flashcards_card").collapse("show");
        $("#panel_flashcards_cards_actions").hide();
        $("#panel_flashcards_cards").hide();
        $(".card-content").prop('disabled', false);
    }
    if (action !== 'edit-card') {
        $("#flashcards_cardedit_save").hide();
        $("#flashcards_cardedit_cancel").hide();
    }
    if (action === 'save-card') {
        //$("#flashcards_cardedit_save").hide();
        $("#flashcards_cardedit_cancel").hide();
        $("#panel_flashcards_card").hide();
        // $("#panel_flashcards_card").collapse("hide");
        $("#panel_flashcards_cards_actions").show();
        $("#panel_flashcards_cards").show();
        $("#button_flashcards_new_card").css({'color': ''});
        showCards(false);
    }
    if (action === 'learn-next') {
        $("#panel_box_navigation").hide();
        $("#flashcards_panel_card_header").hide();
        $('#panel_box_attributes').collapse("hide");
        $("#panel_flashcards_cards_actions").hide();
        $("#panel_flashcards_cards").hide();
        $("#panel_flashcards_card").show();
        // $("#panel_flashcards_card").collapse("show");
        $("#flashcards_panel_learn_buttons").show();
        $(".flashcards_learn").show(); // to fixe the horizontal position
        setLearnButtonsToFixedPosition();
        $("#button_flashcards_learn_play").hide();
        $("#button_flashcards_learn_passed").hide();
        $("#button_flashcards_learn_failed").hide();
        $("#button_flashcards_learn_next").show();
        $("#button_flashcards_learn_stopp").show();
        $(".card-content").prop('disabled', true);
        $("#button_flashcards_learn_next").focus();
//		if(!box.content.private_switch_learn_direction) {
//			document.getElementById("flashcards_language1").scrollIntoView();
//		} else {
//			document.getElementById("flashcards_language2").scrollIntoView();
//		}
    }
    if (action === 'learn-show-other-side') {
        $("#button_flashcards_learn_play").hide();
        $("#button_flashcards_learn_next").hide();
        $("#button_flashcards_learn_passed").show();
        $("#button_flashcards_learn_failed").show();
        $("#button_flashcards_learn_passed").focus();
//		var element = document.getElementById("flashcards_language2");
//		element.scrollIntoView();
    }
    if (action === 'learn-stopp') {
        $("#panel_box_navigation").show();
        $(".flashcards_learn").hide();
        $("#flashcards_panel_learn_buttons").hide();
        $("#button_flashcards_save_box").hide();
        $("#panel_flashcards_card").hide();
        // $("#panel_flashcards_card").collapse("hide");
        $("#panel_flashcards_cards_actions").show();
        $("#panel_flashcards_cards").show();
        unsetLearnButtonsToFixedPosition();
        showCards(false);
    }
    if (action === 'list-boxes') {
        $("#flashcards_navbar_brand").html("Local Flashcards");
        $("#button_flashcards_save_box").hide();
        $("#button_flashcards_learn_play").hide();
        $("#button_share_box").hide();
        $('#panel_box_attributes').collapse("hide");
        $('#panel_flashcards_permissions').collapse("hide");
        $("#panel_flashcards_cards_actions").hide();
        $("#panel_flashcards_cards").hide();
        $("#panel_flashcards_help").hide();
        $("#panel_box_navigation").show();
        $("#panel_cloud_boxes_1").show();
        if(!is_local_channel) {
            $("#panel_cloud_boxes_header").hide();
        }
        $("#flashcards_edit_box").hide();
        $("#panel_flashcards_card").hide();
        blockEditBox = true;
    }
    if (action === 'list-close') {
        $("#panel_flashcards_cards_actions").show();
        $("#panel_flashcards_cards").show();
    }
    if (action !== 'list-boxes') {
        $("#panel_cloud_boxes_1").hide();
        blockEditBox = false;
    }
    if (action === 'show-help') {
        $("#flashcards_navbar_brand").html("Help");
        $("#button_flashcards_save_box").hide();
        $("#button_flashcards_learn_play").hide();
        $("#button_share_box").hide();
        $('#panel_box_attributes').collapse("hide");
        $('#panel_flashcards_permissions').collapse("hide");
        $("#panel_flashcards_cards_actions").hide();
        $("#panel_flashcards_cards").hide();
        $("#panel_cloud_boxes_1").hide();
        $("#panel_box_navigation").show();
        $("#panel_flashcards_help").show();
        $("#panel_flashcards_card").hide();
    }
    if (action !== 'show-help') {
        $("#panel_flashcards_help").hide();
    }
    if (action === 'search-boxes') {
        $("#flashcards_navbar_brand").html("Search Cloud Boxes");
        $("#button_flashcards_save_box").hide();
        $("#button_flashcards_learn_play").hide();
        $('#panel_box_attributes').collapse("hide");
        $('#panel_flashcards_permissions').collapse("hide");
        $("#panel_flashcards_cards_actions").hide();
        $("#panel_flashcards_cards").hide();
        $("#panel_flashcards_help").hide();
        $("#panel_cloud_boxes_1").hide();
        $("#panel_search_cloud_boxes").html('loading...');
        $("#panel_search_cloud_boxes").show();
        if(is_allowed_to_create_box) {
            $("#button_flashcards_close").show();
        } else {
            $("#button_flashcards_close").hide();
        }
        $("#panel_flashcards_card").hide();
    }
    if (action !== 'search-boxes') {
        $("#panel_search_cloud_boxes").hide();
    }
    fixTitleLength();
    if(hasUploads === 1 && box.content.private_autosave) {
        uploadBox();
    }
}

function fillInputsBox() {
    if (box.isEmpty()) {
        $("#flashcards_navbar_brand").html("Create a new Box of Flashcards");
        $("#flashcards_box_title").val('');
        $("#flashcards_box_description").val('');
        $('#flashcards-block-changes').prop('checked', false);
    } else {
        logger.log('Displaying FlashCards titled: ' + box.content.title + '...');
        $("#flashcards_navbar_brand").html(box.content.title);
        $("#flashcards_box_title").val(box.content.title);
        $("#flashcards_box_description").val(box.content.description);
        $('#flashcards-block-changes').prop('checked', box.content.private_block);
    }
}

function fillInputsSettings() {
    $('#flashcards-switch-learn-directions').prop('checked', box.content.private_switch_learn_direction);
    $('#flashcards-switch-learn-all').prop('checked', box.content.private_switch_learn_all);
    $('#flashcards-autosave').prop('checked', box.content.private_autosave);
    $('#flashcards-card-sort').prop('checked', box.content.private_show_card_sort);
    $('#flashcards-default-sort').prop('checked', box.content.private_sort_default);
    $('#flashcards-convenient-search').prop('checked', box.content.private_search_convenient);
    $("#flashcards-learn-system-decks").val(box.content.cardsDecks);
    $("#flashcards-learn-system-deck-repetitions").val(box.content.cardsRepetitionsPerDeck);
    $("#flashcards-learn-system-exponent").val(box.content.cardsDeckWaitExponent);
    $('input.flashcards-column-visibility').each(function (i, checkbox) {
        var col = $($(this)).attr("col")
        var isVisible = box.content.private_visibleColumns[i];
        $($(this)).prop('checked', isVisible);
    });
    var result = box.visualize();
    $("#flashcards-learn-system-visualisation").html(result[1]);
    $("#fc_leitner_calculation").html(result[2]);
}

function showCards(reload) {
    logger.log('showCards()...');
    box.sort();
    createTable(reload);
    setCardsStatus();
    logger.log('tables created, showCards() is finished');
}

function createTable(reload) {
    var html = "";
    if (box.content.cards == null) {
        return;
    }
    if (box.content.cards.length < 1) {
        removeRows();
        return;
    }
    var cards = box.getCardsArrayFiltered();
    if ($('#flashcards_table').length < 1 || reload) {
        logger.log('creating table head...');
        html += '<table class="table" id="flashcards_table">';
        html += getColumnElements();
        if(box.content.private_search_convenient) {
            html += '<tr  style="display: none;">';
        } else {
            html += '<tr>';
        }
        var i;
        for (i = 0; i < 11; i++) {
            logger.log('col ' + i + ' is visible = ' + box.content.private_visibleColumns[i]);
            if (box.content.private_visibleColumns[i]) {
                html += '<td>';
                html += '<input class="form-control cards-filter" type="text" filterCol="' + i + '" value="' + box.content.private_filter[i] + '">';
                html += '</td>';
            }
        }
        html += '</tr>';
        if(box.content.private_show_card_sort) {            
            html += '<tr>';
            var i;
            for (i = 0; i < 11; i++) {
                if (box.content.private_visibleColumns[i]) {
                    html += '<th scope="col">';
                    // html += box.content.cardsColumnName[i];
                    // html += '<br>';
                    html += '<span>';
                    if (box.content.private_sortColumn == i) {
                        if (!box.content.private_sortReverse) {
                            html += '<i class="fa fa-fw fa-sort-asc fa-lg" sortCol="' + i + '" style="color:red;"></i>';
                            html += '<i class="fa fa-fw fa-sort-desc fa-lg" sortCol="' + i + '"></i>';
                        } else {
                            html += '<i class="fa fa-fw fa-sort-asc fa-lg" sortCol="' + i + '"></i>';
                            html += '<i class="fa fa-fw fa-sort-desc fa-lg" sortCol="' + i + '" style="color:red;"></i>';
                        }
                    } else {
                        html += '<i class="fa fa-fw fa-sort-asc fa-lg" sortCol="' + i + '"></i>';
                        html += '<i class="fa fa-fw fa-sort-desc fa-lg" sortCol="' + i + '"></i>';
                    }
                    html += '</span>';
                    html += '</th>';
                }
            }
            html += '</tr>';
        }
        logger.log('creating table body...');
        html += createCardRows(cards);
        html += '</table>';
        $('#panel_flashcards_cards').html(html);
    } else {
        replaceRows(cards);
    }
}
function removeRows() {
    logger.log('removeRows() remove all rows first...');
    $('#flashcards_table').find('.flashcards-table-row').each(function (i, tr) {
        //logger.log('remove row: ' + i);
        tr.remove();
    })
}
function replaceRows(cards) {
    removeRows();
    logger.log('replaceRows() sort and filter cards...');
    html = createCardRows(cards);
    logger.log('replaceRows() insert into table...');
    $('#flashcards_table tr:last').after(html);
    logger.log('replaceRows() ready');
}
function createCardRows(cards) {
    logger.log('createCardRows() start...');
    var html = '';
    if (cards == null) {
        return html;
    }
    for (i = 0; i < cards.length; i++) {
        //logger.log('add row: ' + i);
        html += '<tr class="flashcards-table-row" cardid="' + cards[i].content[0] + '">';
        var j;
        for (j = 0; j < 11; j++) {
            if (box.content.private_visibleColumns[j] || box.searchResultColumns[j]) {
                html += '<td>';
                if (j == 0 || j == 5 || j == 9) {
                    if (cards[i].content[j] != 0) {
                        html += new Date(cards[i].content[j]).toLocaleString();
                    }
                } else {
                    var marked = mark(cards[i].content[j], box.search)
                    html += marked;
                }
                html += '</td>';
            }
        }
        html += '</tr>';
    }
    return html;
}
function getColumnElements() {
    var html = '';
    var counter = 0;
    var j;
    for (j = 0; j < 11; j++) {
        if (box.content.private_visibleColumns[j] || box.searchResultColumns[j]) {
            counter++;

        }
    }
    for (j = 0; j < 11; j++) {
        if (box.content.private_visibleColumns[j] || box.searchResultColumns[j]) {
            html += '<col width="' + 100 / counter + '%">';
        }
    }
    return html;
}
function mark(text, search) {
    if(search.trim() === "") {
        return text;
    }
    var searchParts = new Array();
    var parts = search.split(/\s+/);
    for (var el of parts) {
        if(el.trim() !== "") {
            searchParts.push(el);
        }
    }
    var remaining = text;
    var result = "";
    var next = true;
    while(next) {
        var start = -1;
        var part;
        for (var el of searchParts) {
            var testStart = remaining.toLowerCase().indexOf(el.toLowerCase());
            if(testStart < 0) {
                continue;
            }
            if(start === -1) {
                start = testStart;
                part = el;
            } else if(start !== -1 && testStart < start) {
                start = testStart;
                part = el;
            }
        }
        if(start > -1) {
            result += remaining.substring(0, start) + '<mark>' + remaining.substring(start, start + part.length) + '</mark>';
            remaining = remaining.substring(start + part.length, text.length);
        } else {
            result += remaining;
            next = false;
        }
    }
    return result;
} 

function setCardsStatus() {
    logger.log('setCardsStatus() ...');
    if (box.content.cards == null) {
        return;
    }
    var l = box.content.cards.length;
    var filteredCards = box.getCardsArrayFiltered();
    html = '';
    if (filteredCards.length == l) {
        html += l;
    } else {
        html += filteredCards.length + ' out of ' + l;
    }
    $('#span_flashcards_cards_actions_status').html(html);
    if(l > 0) {
        if(box.content.private_search_convenient) {
            $('#button_flashcards_search_cards').show();
        } else {
            $('#button_flashcards_search_cards').hide();
            $('#input_flashcards_search_cards').hide();
            $('#input_flashcards_search_cards').val("");
            box.search = "";
        }
    }
    var due = 0;
    var i;
    for (i = 0; i < filteredCards.length; i++) {
        var cardContent = filteredCards[i].content;
        var dueCard = new Card();
        dueCard.setContent(cardContent);
        if (dueCard.isDue(new Date().getTime())) {
            due++;
        }
    }
    if (due > 0) {
        $('#span_flashcards_cards_due').html(due);
        var colorButton = $("i.fa-graduation-cap").css('color'); // TODO: does not work
        colorButton = "#007bff";
        $("#button_flashcards_learn_play").prop("disabled", false).css({"color": colorButton});
        $('#button_flashcards_learn_play').show();
    } else {
        if (box.content.private_switch_learn_all) {
            $('#span_flashcards_cards_due').html('');
            $("#button_flashcards_learn_play").prop("disabled", false).css({'color': ''});
            $('#button_flashcards_learn_play').show();
        } else {
            $('#button_flashcards_learn_play').hide();
        }
    }
}

var viewportWidthStored = 0;
function hasViewportWidthChanged() {
    var w = $(window).width();
    if (w !== viewportWidthStored) {
        viewportWidthStored = w;
        return true;
    }
    return false;
}

var leftLearnButtons = 0;
var topLearnButtons = 0;
function setLearnButtonsToFixedPosition() {
    if (hasViewportWidthChanged()) {
        var pos = $("#flashcards_panel_learn_buttons").offset();
        topLearnButtons = pos.top;
        $("#flashcards_panel_learn_buttons").css({'position': ''}).css({'top': ''}).css({'left': ''});
        pos = $("#button_flashcards_learn_stopp").offset();
        leftLearnButtons = pos.left - 5;
    }
    $("#flashcards_panel_learn_buttons").css({'position': 'fixed'}).css({'left': leftLearnButtons}).css({'z-index': 10});
    var heightButtons = $("#flashcards_panel_learn_buttons").outerHeight();
    $("#flashcards_main_card").css({'z-index': 5}).css({'margin-top': heightButtons});
}

function unsetLearnButtonsToFixedPosition() {
    $("#flashcards_panel_learn_buttons").css({'position': ''}).css({'top': ''});
    $("#flashcards_main_card").css({'margin-top': ''});
}

function showCard(id) {
    var html = '';
    if (id == 0) {
        logger.log('showCard(id) show new card...');
        card = new Card();
        $('#flashcards_language1').val("");
        $('#flashcards_language2').val("");
        $('#flashcards_description').val("");
        $('#flashcards_tags').val("");
        card.edit();
    } else {
        logger.log('showCard(id) show existing card...');
        var cardArray = box.getCard(id).content;
        card = new Card();
        card.setContent(cardArray);
        side1 = card.content[1];
        $('#flashcards_language1').val(card.content[1]);
        $('#flashcards_language2').val(card.content[2]);
        $('#flashcards_description').val(card.content[3]);
        $('#flashcards_tags').val(card.content[4]);
        card.edit();
        if (card.content[9] != 0) {
            html = 'Card is in deck ' + card.content[6];
            if (box.content.cardsRepetitionsPerDeck > 0) {
                html += '.' + card.content[7];
            }
            html += ', learned ' + card.content[8] + ' times, last time at ';
            html += new Date(card.content[9]).toLocaleString();
        }
    }
    conductGUIelements('edit-card');
    $('#flashcard_learn_card_details').html(html);
    validateUserInputCard();
}

$(document).on("input", "#flashcards_language1", function () {
    validateUserInputCard();
});

$(document).on("input", "#flashcards_language2", function () {
    validateUserInputCard();
});

$(document).on("input", "#flashcards_description", function () {
    validateUserInputCard();
});

$(document).on("input", "#flashcards_tags", function () {
    validateUserInputCard();
});

function validateUserInputCard() {
    logger.log('validate user input for card...');
    var side1 = $('#flashcards_language1').val();
    var side2 = $('#flashcards_language2').val();
    var descr = $('#flashcards_description').val();
    var tags = $('#flashcards_tags').val();
    var maxL = box.content.maxLengthCardField;
    if (side1.length < 1 || side2.length < 1 || side1.length > maxL || side2.length > maxL || descr.length > maxL || tags.length > maxL) {
        logger.log('card content to short or to long > disable save button for card...');
        $("#flashcards_cardedit_save").prop("disabled", true);
        return false;
    }
    logger.log('enable save button for card...');
    $("#flashcards_cardedit_save").prop("disabled", false);
    return true;
}

function saveCard() {
    logger.log('saveCard() save card...');
    if (!validateUserInputCard()) {
        return;
    }
    card.content[1] = $('#flashcards_language1').val();
    card.content[2] = $('#flashcards_language2').val();
    card.content[3] = $('#flashcards_description').val();
    card.content[4] = $('#flashcards_tags').val();
    if (card.save()) {
        var id = card.content[0];
        if (!box.getCard(id)) {
            logger.log('saveCard() add card to box...');
            box.content.cards.push(card);
        }
        box.save('card');
    }
    conductGUIelements('save-card');
}

function validateInputsBox() {
    var box_title = $('#flashcards_box_title').val();
    if (box_title.length < 10 || box_title.length > 60) {
        $("#button_flashcards_save_box").prop("disabled", true);
        // logger.log('box title to short or to long');
        return false;
    }
    var box_description = $('#flashcards_box_description').val();
    if (box_description.length < 10 || box_description.length > 800) {
        $("#button_flashcards_save_box").prop("disabled", true);
        // logger.log('box description to short or to long');
        return false;
    }
    $("#button_flashcards_save_box").removeAttr('disabled');
    $("#flashcards_navbar_brand").html(box_title);
    return true;
}

$(document).on("click", "#button_flashcards_save_box", function () {
    logger.log('clicked button save box');
    saveBoxSettings();
});

function saveBoxSettings() {
    if (validateInputsBox()) {
        box.edit();
        box.setTitle($('#flashcards_box_title').val());
        box.setDescription($('#flashcards_box_description').val());
        box.content.private_block = $('#flashcards-block-changes').prop('checked');
        // settings
        box.content.private_switch_learn_direction = $('#flashcards-switch-learn-directions').prop('checked');
        box.content.private_switch_learn_all = $('#flashcards-switch-learn-all').prop('checked');
        box.content.private_autosave = $('#flashcards-autosave').prop('checked');
        box.content.private_show_card_sort= $('#flashcards-card-sort').prop('checked');
        box.content.private_sort_default = $('#flashcards-default-sort').prop('checked');
        box.content.private_search_convenient = $('#flashcards-convenient-search').prop('checked');
        box.content.cardsDecks = $('#flashcards-learn-system-decks').val();
        box.content.cardsRepetitionsPerDeck = $('#flashcards-learn-system-deck-repetitions').val();
        box.content.cardsDeckWaitExponent = $('#flashcards-learn-system-exponent').val();
        $('input.flashcards-column-visibility').each(function (i, checkbox) {
            box.content.private_visibleColumns[i] = $($(this)).prop('checked');
            // logger.log(i + $($(this)).prop('checked'));
        });
        box.save('box');
        conductGUIelements('save-box');
    }
}

$(document).on("input", "#flashcards_box_title", function () {
    validateInputsBox();
});

$(document).on("input", "#flashcards_box_description", function () {
    validateInputsBox();
});

$(document).on("click", "#button_flashcards_learn_play", function () {
    if(box.content.private_sort_default) {
        var tmpIndex = box.content.private_sortColumn;
        var tempRevers = box.content.private_sortReverse;
        box.sortBy(0, false);
        box.sortBy(6, false);
        box.content.private_sortColumn = tmpIndex;
        box.content.private_sortReverse = tempRevers;
    }
    learnNext(true);
});

$(document).on("click", "#button_flashcards_learn_next", function () {
    $('#flashcards_language1').val(card.content[1]);
    $('#flashcards_language2').val(card.content[2]);
    $('#flashcards_description').val(card.content[3]);
    $('#flashcards_tags').val(card.content[4]);
    conductGUIelements('learn-show-other-side');
});

$(document).on("click", "#button_flashcards_learn_stopp", function () {
    learnStopp();
});

$(document).on("click", "#button_flashcards_learn_passed", function () {
    progress(true);
});

$(document).on("click", "#button_flashcards_learn_failed", function () {
    progress(false);
});

var filteredCards = [];
function learnNext(takeNext) {
    var idLast = 0;
    if (takeNext) {
        filteredCards = box.getCardsArrayFiltered();
    } else {
        idLast = card.content[0];
    }
    var hasNext = false;
    var i;
    for (i = 0; i < filteredCards.length; i++) {
        var cardContent = filteredCards[i].content;
        card = new Card();
        card.setContent(cardContent);
        var tempI = card.content[0];
        if (!takeNext) {
            if (idLast == card.content[0]) {
                takeNext = true;
            }
            continue;
        }
        if (!box.content.private_switch_learn_all) {
            if (!card.isDue(new Date().getTime())) {
                continue;
            }
        }
        hasNext = true;
        break;
    }
    if (!hasNext) {
        learnStopp();
    } else {
        if (!box.content.private_switch_learn_direction) {
            $('#flashcards_language1').val(card.content[1]);
            $('#flashcards_language2').val("");
        } else {
            $('#flashcards_language1').val("");
            $('#flashcards_language2').val(card.content[2]);
        }
        $('#flashcards_description').val("");
        $('#flashcards_tags').val(card.content[4]);
        html = '';
        if (card.content[9] != 0) {
            html = 'Card is in deck ' + card.content[6];
            if (box.content.cardsRepetitionsPerDeck > 0) {
                html += '.' + card.content[7];
            }
            html += ', learned ' + card.content[8] + ' times';
            if (!box.content.private_switch_learn_all) {
                html += ', last time at ' + new Date(card.content[9]).toLocaleString();
            }
        }
        $('#flashcard_learn_card_details').html(html);
        conductGUIelements('learn-next');
    }
}

function learnStopp() {
    box.save('progress');
    filteredCards = [];
    conductGUIelements('learn-stopp');
}

function progress(passed) {
    if (box.content.private_switch_learn_all && passed) {
        card.content[8] = card.content[8] + 1; // learn count
    } else {
        card.move(passed); // this card is not an element of the Box object
    }
    learnNext(false);
}

/**
 * This opens a modal dialog where the user can confirm to delete a box
 */
$(document).on("click", "#link_delete_box", function () {
    logger.log('clicked link delete box');
    var boxid_delete = $($(this)).attr("boxid");
    var title_box_delete = $($(this)).attr("title_box_delete");
    logger.log('Modal to delete boxid = ' + boxid_delete + ', tile = ' + title_box_delete);
    $("#button_delete_box").attr("boxid", boxid_delete);
    $("#modal_body_delete_box").html("Do you really want to delete '" + title_box_delete + "'?");
    $('#delete_box_modal').modal('show');
});

/**
 * This deletes a box on the server
 */
$(document).on("click", "#button_delete_box", function () {
    var boxid_delete = $($(this)).attr("boxid");
    // $('a[href$="' + link_box_delete + '"]').hide();
    $('#delete_box_modal').modal('hide');
    animate_on();
    logger.log('Sending request to delete box id: ' + boxid_delete + '...');
    $.post(postUrl + "/delete", {boxID: boxid_delete}, function (data) {
        animate_off();
        if (data['status']) {
            logger.log("Successfully deleted box " + boxid_delete + " on server. Status: " + data['status']);
            loadCloudBoxes()
        } else {
            logger.log("Error saving box: " + data['errormsg']);
            loadCloudBoxes();
        }
        return false;
    },
        'json');
});

function animate_on() {
    $('#button_share_box').find('.fa').addClass("fa-spin").addClass("fa-fw");
    fixTitleLength();
}

function animate_off() {
    $('#button_share_box').find('.fa').removeClass("fa-spin").removeClass("fa-fw");
}

$(document).on("click", "#button_share_box", function () {
    logger.log('clicked button share box');
    uploadBox();
});

function uploadBox() {
    if (box.isEmpty()) {
        return;
    }
    var tempTitle = box.content.title;
    if (tempTitle === "") {
        return;
    }

    var boxUpload = box.getContentToUpload();
    logger.log('Sending request to upload a box: ' + boxUpload.content.title + '...');

    animate_on();

    $.post(postUrl + "/upload", {box: boxUpload.content}, function (data) {
        animate_off();
        if (data['status']) {
            logger.log("Successfully uploaded box " + box.content.title + " on server. Status: " + data['status']);
            var box_id = data['resource_id'];
            var box_public_id = data['resource_public_id'];
            box.content.lastShared = data['box']['lastShared'];
            var cardIDsReveived = data['cardIDsReceived'];
            logger.log('local boxID = "' + box.content.boxID + '", remote boxID = "' + box_id + '", cards received = "' + cardIDsReveived + '"');
            if (box.content.boxID.length < 1 && box_id.length > 0) {
                // reload the page
                logger.log('Box was created in Hubzilla DB. The new box ID is "' + box_id + '". Reloading page...');
                box.content.boxID = box_id;
                box.content.boxPublicID = box_public_id;
                box.checkUploadMarkers(cardIDsReveived);
                box.store();
                var url = postUrl + '/' + box_id;
                logger.log('Redirecting to new URL: ' + url + '...');
                window.location.assign(url);
            } else {
                // Merge the remote changes.
                // This is always a merge (not import) because the public ID is the same in local and remote.
                box.checkUploadMarkers(cardIDsReveived);
                var remoteBox = new Box();
                remoteBox.setContent(data['box']);
                importBox(remoteBox);
                setShareButton();
            }
        } else {
            logger.log("Error uploading box: " + data['errormsg']);
        }
    },
        'json');
}

function redirectToAppRoot() {
    logger.log('Trying to redirect to plugin base URL');
    var href = window.location.href;
    // do not redirect if *.html is opened in file system (not loaded from Web/Hubzilla)
    if (isStartedFromLocalFileSystem()) {
        logger.log('Do not redirect because this seems to run in a local file system, href = ' + href);
        return false;
    }
    var hrefArr = href.split('/');
    if (hrefArr.length < 7) {
        logger.log('Not redirecting to boxID because href in URL has less then 7 elements, href = ' + href);
        return false;
    }
    var url = '';
    var i;
    for (i = 0; i < 6; i++) {
        if (url.length > 0) {
            url += '/';
        }
        url += hrefArr[i];
    }
    logger.log('Redirecting to base URL of plugin: ' + url + '...');
    window.location.assign(url);
    return true;
}

function downLoadBoxForURL() {
    // get boxID from URL
    var path = window.location.pathname;
    logger.log("Try to download a box from a URL with path: " + path);
    var parts = path.split('/');
    var box_id = parts[3];
    if (!box_id) {
        logger.log("Do not download a box for the URL. Why? The URL path (" + path + ") was splitted by slashes. The fourth part does not exist. It should be the box ID.");
        return false;
    }
    animate_on();
    logger.log('Sending request to download a box: ' + box_id + '...');
    $.post(postUrl + "/download/", {boxID: box_id}, function (data) {
        animate_off();
        if (data['status']) {
            logger.log("Successfully downloaded box " + box_id + " on server. Status: " + data['status']);
            // Merge the remote changes.
            // This is always a merge (not import) because the public ID is the same in local and remote.
            var remoteBox = new Box();
            remoteBox.setContent(data['box']);
            remoteBox.removeAllUploadMarkers();
            importBox(remoteBox);
        } else {
            logger.log("Error downloading box: " + data['errormsg']);
            loadCloudBoxes(); // TODO: Show list of boxes of "New box"
        }
    },
        'json');
    return true;
}

function isStartedFromLocalFileSystem() {
    var href = window.location.href;
    if (href.startsWith('/') || href.startsWith('file')) {
        return true;
    }
    return false;
}

function importBox(importBox) {
    logger.log('Importing box...')
    importBox.validate();
    logger.log('Public id of remote box "' + importBox.content.boxPublicID + '", public id of local box "' + box.content.boxPublicID + '"');
    logger.log('Box id of remote box "' + importBox.content.boxID + '", box id of local box "' + box.content.boxID + '"');
    if (importBox.content.boxID == box.content.boxID) {
        logger.log('ID of local and remote box are the same > Merging...');
        box.merge(importBox);
    } else {
        logger.log('ID of remote box is different from local one. Do not merge the boxes. Instead: Import the remote box and display it...');
        box = importBox;
    }
    loadStartPage();
    box.store();
}

/**
 * Creates a table to visualize the flashcards system.
 */
$(document).on("input", ".flashcards-learn-params", function () {
    visualiseLearnSystem();
});

function visualiseLearnSystem() {
    var count = $('#flashcards-learn-system-decks').val();
    var repetitions = $('#flashcards-learn-system-deck-repetitions').val();
    var exponent = $('#flashcards-learn-system-exponent').val();
    // validate inputs and generate html table
    var result = box.visualize(count, exponent, repetitions);
    $("#flashcards-learn-system-visualisation").html(result[1]);
    $("#fc_leitner_calculation").html(result[2]);
}

$(document).on("click", "#button_flashcards_settings_default", function () {
    $('#flashcards-autosave').prop('checked', true);
    $('#flashcards-card-sort').prop('checked', false);
    $('#flashcards-default-sort').prop('checked', true);
    $('#flashcards-convenient-search').prop('checked', true);
    $('#flashcards-switch-learn-all').prop('checked', false);
    $("#flashcards-learn-system-decks").val('7');
    $("#flashcards-learn-system-deck-repetitions").val('3');
    $("#flashcards-learn-system-exponent").val('3');
    $('input.flashcards-column-visibility').each(function (i, checkbox) {
        if (i == 1 || i == 2) {
            $($(this)).prop('checked', true);
        } else {
            $($(this)).prop('checked', false);
        }
    });
    visualiseLearnSystem();
});

$(document).on("click", "i.fa-sort-asc", function () {
    box.content.private_sortColumn = parseInt($(this).attr("sortCol"));
    box.content.private_sortReverse = false;
    showCards(false);
    colorSortArrow();
});

$(document).on("click", "i.fa-sort-desc", function () {
    box.content.private_sortColumn = parseInt($(this).attr("sortCol"));
    box.content.private_sortReverse = true;
    showCards(false);
    colorSortArrow();
});

function colorSortArrow() {
    $('i.fa-sort-desc').each(function (i, obj) {
        var col = $(this).attr("sortCol");
        if (box.content.private_sortColumn == col && box.content.private_sortReverse) {
            $(this).css({'color': 'red'});
        } else {
            $(this).css({'color': 'black'});
        }
    });
    $('i.fa-sort-asc').each(function (i, obj) {
        var col = $(this).attr("sortCol");
        if (box.content.private_sortColumn == col && !box.content.private_sortReverse) {
            $(this).css({'color': 'red'});
        } else {
            $(this).css({'color': 'black'});
        }
    });
}

$(document).on("input", "input.cards-filter", function () {
    $('input.cards-filter').each(function (i, obj) {
        box.content.private_filter[$(this).attr('filterCol')] = $(this).val();
    });
    showCards(false);
});

$(document).on("input", "#input_flashcards_search_cards", function () {
    logger.log('Some input in field for convenient search. Clear column filter');
    var searchStr = $('#input_flashcards_search_cards').val();
    var l = searchStr.length;
    var lastChar = searchStr.substring(searchStr.length - 1)
    if (l > 2 && lastChar !== " ") {
        // box.content.private_filter = ["", "", "", "", "", "", "", "", "", "", ""];
        box.search = searchStr;
        showCards(false);
    } else {
        if(box.search.length > 0 && l === 0) {
            // User deleted the search string
            box.search = "";
            showCards(true);
        }
        logger.log('Search string less than 3 characters: ' + searchStr);
        return;
    }
});

$(document).on("click", "#button_flashcards_new_card", function () {
    showCard(0);
});

$(document).on("click", "#flashcards_cardedit_save", function () {
    saveCard();
});

$(document).on("click", "#flashcards_cardedit_cancel", function () {
    conductGUIelements('save-card');
});

$(document).on("click", ".flashcards-table-row", function () {
    showCard($(this).attr('cardid'));
});

$(document).on("click", "#flashcards_navbar_brand", function () {
    logger.log('Clicked on title in navbar');
    if(blockEditBox) {
        return;
    }
    if ($('#button_flashcards_save_box').is(':visible')) {
        saveBoxSettings();
    } else {
        conductGUIelements('edit-box');
    }
});
$(document).on("click", "#flashcards_edit_box", function () {
    logger.log('Clicked on flashcards_edit_box');
    if(blockEditBox) {
        return;
    }
    conductGUIelements('edit-box');
});

$(document).on("click", "#flashcards_new_box", function () {
    logger.log('Clicked on flashcards_new_box');
    box = new Box();
    box.store();
    loadStartPage();
});

$(document).on("click", "#flashcards_show_help", function () {
    logger.log('Clicked on flashcards_show_help');
    conductGUIelements('show-help');
});

$(document).on("click", "#button_flashcards_close", function () {
    logger.log('Clicked on button_flashcards_close');
    conductGUIelements('start');    
});

$(document).on("click", "#flashcards_show_boxes", function () {
    logger.log('Clicked on flashcards_show_boxes');
    loadCloudBoxes();
});


var localBoxes = [];

function loadCloudBoxes() {
    localBoxes = [];
    animate_on();
    logger.log('Sending request to download a list of cloud boxes...');
    $.post(postUrl + '/list/', '', function (data) {
        animate_off();
        if (data['status']) {
            logger.log("Donwnload of boxes successfull. Status: " + data['status']);
            $("#panel_flashcards_cards").hide();
            var boxes = data['boxes'];
            if (boxes) {
                if (boxes.length > 0) {
                    localBoxes = boxes;
                    createBoxList();
                    conductGUIelements('list-boxes');
                    return;
                } else {
                    logger.log("The list of own cloud boxes is empty");
                }
            } else {
                logger.log("No boxes received");
            }
            if(is_allowed_to_create_box) {
                box = new Box();
                box.store();
                loadStartPage();
            } else {                
                var html = 'No flashcards on this server or no permissions to view them';
                $("#panel_cloud_boxes_header").html('');
                $("#panel_cloud_boxes_content").html(html);
                conductGUIelements('list-boxes');
                return;
            }
        } else {
            logger.log("Error downloading list of boxes: " + data['errormsg']);
            $("#panel_flashcards_cards").html(data['errormsg']);
            $("#panel_flashcards_cards").show();
        }
    },
        'json');
}

function createBoxList() {
    createBoxListHeader();
    createBoxListContent(true);
}

function createBoxListHeader() {
    var html = '';
    html += '<div id="flashcards_boxes_list_header" class="clearfix form-group checkbox">';
        html += '<label for="id_XY">All Flashcards on this server or just your own?</label>';
        html += '<div class="float-right"><input type="checkbox" name="flashcards_own_boxes" id="id_flashcards_own_boxes" value="1" checked="checked" /><label class="switchlabel" for="id_flashcards_own_boxes"> <span class="onoffswitch-inner" data-on="All" data-off="Own"></span><span class="onoffswitch-switch"></span></label></div>';
        html += '<small class="form-text text-muted">To search flashcards on other servers use menu -> "Search".</small>';
    html += '</div>';
    $("#panel_cloud_boxes_header").html(html);
}

function createBoxListContent(showAllBoxes) {
    var html = '';
    var i;
    for (i = 0; i < localBoxes.length; i++) {
        var cloudBox = localBoxes[i];
        if (!cloudBox) {
            logger.log('Received a box that is NULL (seems to be a bug).');
            continue;  // This happened in dev (alpha)
        }
            var currentOwner = cloudBox["current_owner"];
        if(!showAllBoxes) {
            if(flashcards_editor !== currentOwner) {
                continue;
            }
        }
        var description = cloudBox["description"];
        if (!description) {
            continue;
        }
        description = description.replace(/\n/g, '<br>');
        html += '<div class="row">';
        html += '   <div class="col-sm-12">';
        html += '       <br><h3><a href="' + cloudBox["current_url"] + '" name="load_box">' + cloudBox["title"] + '</a></h3>';
        html += '   </div>';
        html += '</div>';
        html += '<div class="col-sm-12">';
        html += '   <b>Description:</b><br>';
        html += '   ' + description + '';
        html += '   <br><b>Owner: </b>' + cloudBox["current_owner"] + '';
        html += '   <br><b>Size: </b>' + cloudBox["size"] + '';
        if(flashcards_editor === '') {            
            html += '   <br>Unknow observer. Please login to view this box';
        }else if (cloudBox["boxID"] !== box.content.boxID) {
            if (flashcards_editor === currentOwner) {
                html += '       &nbsp;<b>Delete box: </b>&nbsp;';
            } else {
                html += '       &nbsp;<b>Delete learn results: </b>&nbsp;';
            }
            html += '       <i class="fa fa-trash" id="link_delete_box" boxid="' + cloudBox["boxID"] + '" title_box_delete="' + cloudBox["title"] + '"></i>';
        }
        html += '</div>';
    }
    html += '</div>';
    $("#panel_cloud_boxes_content").html(html);
}

$(document).on("click", "#id_flashcards_own_boxes", function () {
    logger.log('Clicked on checkbox id_flashcards_own_boxes');
    var showAllBoxes = $('#id_flashcards_own_boxes').prop('checked');
    createBoxListContent(showAllBoxes);
});

function searchCloudBoxes() {
    animate_on();
    logger.log('Sending request to search for boxes...');
    $.post(postUrl + '/search/', '', function (data) {
        animate_off();
        if (data['status']) {
            logger.log("Search for boxes successfull. Status: " + data['status']);
            var boxes = data['boxes'];
            if (boxes) {
                if (boxes.length > 0) {
                    createListFoundBoxes(boxes);
                } else {
                    logger.log("No cloud boxes found");
                    $("#panel_search_cloud_boxes").html('No cloud boxes found');
                }
            } else {
                logger.log("But the list of cloud boxes was empty");
            }
        } else {
            logger.log("Error searching for boxes: " + data['errormsg']);
            $("#panel_search_cloud_boxes").html(data['errormsg']);
        }
        $("#button_share_box").hide();
        if(is_allowed_to_create_box) {
            $("#button_flashcards_close").show();
        } else {
            $("#button_flashcards_close").hide();
        }
    },
        'json');
}

$(document).on("click", "#flashcards_search_boxes", function () {
    logger.log('Clicked on flashcards_search_boxes');
    conductGUIelements('search-boxes');
    searchCloudBoxes();
});

function createListFoundBoxes(boxes) {
    var html = '';
    html += '<div class="container-fluid">';
    // list
    var i;
    for (i = 0; i < boxes.length; i++) {
        var cloudBox = boxes[i];
        if (!cloudBox) {
            logger.log('Received a box URL that is NULL (seems to be a bug).');
            continue;  // This happened in dev (alpha)
        }
        html += '<div class="row">';
        html += '   <div class="col-sm-12">';
        html += '       <br><h3><a href="' + cloudBox + '" name="load_box">' + cloudBox  + '</a></h3>';
        html += '   </div>';
        html += '</div>';
    }
    html += '</div>';
    $("#panel_search_cloud_boxes").html(html);
}

$(document).on("click", "#run_unit_tests", function () {
    logger.log('clicked run unit tests');
    test_run();
    showCards();
});

$(window).on('resize', function () {
    if ($("#button_flashcards_learn_stopp").css('display') !== 'none') {
        setLearnButtonsToFixedPosition();
    }
    fixTitleLength();
});

function fixTitleLength() {
    if ($("#panel_box_navigation").css('display') !== 'none') {
        logger.log('Title is visible when rezising');
        if ($("#button_share_box").css('display') !== 'none') {
            var s = $("#flashcards_navbar_brand").html();;
            $("#flashcards_navbar_brand").html(s);
            var i = s.length - 1;
            for (i; i > 0; i--) {
                var posMenu = $("#panel_box_navigation").position();
                var topMenu = posMenu.top;
                var posButton = $("#button_share_box").position();
                var topButton = posButton.top;
                if (topMenu < topButton) {
                    s = s.substr(0, i);
                    $("#flashcards_navbar_brand").html(s);
                } else {
                    break;
                }
            }
        }
    }
}

$(document).on("click", "#flashcards_perms", function () {
    var url = postUrl + '/acl';
    logger.log('Sending request to get ACL for box ' + box.content.boxID + ' ... (' + url + ")");
    $.post(url, {boxID: box.content.boxID}, function (data) {
        if (data['status']) {
            logger.log("Donwnload of boxes successfull. Status: " + data['status']);
            var acl_modal = data['acl_modal'];
            var permissions_panel = data['permissions_panel'];
            if (acl_modal && permissions_panel) {
            } else {
                logger.log("Response not complete. Either empty ACL modal or permissions panel.");
                return;
            }
            $('#acl_modal_flashcards_cards').html(acl_modal);
            $('#panel_flashcards_permissions').html(permissions_panel);
            $('#panel_flashcards_permissions').collapse("show");
            $("#button_flashcards_save_box").hide();
            $("#panel_flashbox_settings").collapse("hide");
            $('#panel_box_attributes').collapse("hide");
            $("#button_flashcards_close").show();
        } else {
            logger.log("Failed to load ACL. Error message is: " + data['errormsg']);
        }
    });
})

function showACLbutton() {
    if (is_allowed_to_create_box == 1 && box.content.boxID !== "") {
        $("#flashcards_perms").show();
    } else {
        $("#flashcards_perms").hide();
    }
}

$(document).on("click", "#button_flashcards_search_cards", function () {
    if($("#input_flashcards_search_cards").is(":visible")) {
        $('#input_flashcards_search_cards').hide();
        box.search = "";
        $('#input_flashcards_search_cards').val("");
        showCards();
    } else {
        $('#input_flashcards_search_cards').show();
        $('#input_flashcards_search_cards').focus();
    }
})

//------------------------
// ###
// ### Start Unit Tests

var test_card_00 = new Card();
test_card_00.setContent(["1528468538430", "aa aa", "bb bb", "cc cc", "Dd aa", "1528468538430", "0", "0", ""]);
test_card_00.validate();
var test_card_01 = new Card();
test_card_01.setContent(["1528468567982", "bb bb", "cc cc", "aa aa", "dd bb", "1528468567982", "0", "0", "0", ""]);
test_card_01.validate();
var test_card_02 = new Card();
test_card_02.getContent(["1528468591486", "cc cc", "aa aa", "bb bb", "Dd cc", "1528468591486", "0", "0", "0", "1528468591486"]);
test_card_02.validate();

function test_run() {
    var oldBox = box;
    logger.log("Run all tests...");
    if (!test_box_checkValues()) {
        logger.log("Test failed: test_box_checkValues()");
        return;
    } else {
        logger.log("Test passed: test_box_checkValues()");
    }
    if (!test_box_validate()) {
        logger.log("Test failed: test_box_validate()");
        return;
    } else {
        logger.log("Test passed: test_box_validate()");
    }
    if (!test_box_sortBy()) {
        logger.log("Test failed: test_box_sortBy()");
        return;
    } else {
        logger.log("Test passed: test_box_sortBy()");
    }
    if (!test_box_getCardsArrayFiltered()) {
        logger.log("Test failed: test_box_getCardsArrayFiltered()");
        return;
    } else {
        logger.log("Test passed: test_box_getCardsArrayFiltered()");
    }
    if (!test_card_isDue()) {
        logger.log("Test failed: test_card_isDue()");
        return;
    } else {
        logger.log("Test passed: test_card_isDue()");
    }
    if (!test_card_move()) {
        logger.log("Test failed: test_card_move()");
        return;
    } else {
        logger.log("Test passed: test_card_move()");
    }
    if (!test_box_merge()) {
        logger.log("Test failed: test_box_merge()");
        return;
    } else {
        logger.log("Test passed: test_box_merge()");
    }
    logger.log("Test result: all tests passed.");
    box = oldBox;
}
function test_box_checkValues() {
    logger.log("Run test_box_checkValues()...");
    var testBox = new Box();
    // text values
    if (testBox.checkString(1, 10) !== "" || testBox.checkString([1, "2"], 10) !== "" || testBox.checkString("123", 2) !== "12") {
        return false;
    }
    // elements containing text
    testBox.getContent().title = 1;
    testBox.getContent().description = "hallo";
    testBox.getContent().creator = ["1"];
    testBox.validate();
    if (testBox.getContent().title !== "" || testBox.getContent().description !== "hallo" || testBox.getContent().creator !== "") {
        return false;
    }
    testBox.getContent().lastShared = [455];
    testBox.getContent().boxPublicID = ">";
    testBox.getContent().size = 1.4;
    testBox.getContent().lastEditor = ["150"];
    testBox.getContent().lastChangedPublicMetaData = -10;
    testBox.getContent().lastChangedPrivateMetaData = -10;
    testBox.getContent().maxLengthCardField = 10000;
    testBox.getContent().cardsDecks = -5;
    testBox.getContent().cardsDeckWaitExponent = "-4";
    testBox.validate();
    if (testBox.getContent().lastShared !== 455 || testBox.getContent().boxPublicID !== ">") {
        return false;
    }
    if (testBox.getContent().size !== 1 || testBox.getContent().lastEditor !== "" || testBox.getContent().lastChangedPublicMetaData !== 0 || testBox.getContent().lastChangedPrivateMetaData !== 0) {
        return false;
    }
    if (testBox.getContent().maxLengthCardField !== 1000 || testBox.getContent().cardsDecks !== 4 || testBox.getContent().cardsDeckWaitExponent !== 1) {
        return false;
    }
    testBox.getContent().cardsDecks = "1010";
    testBox.getContent().cardsDeckWaitExponent = 11;
    testBox.getContent().cardsRepetitionsPerDeck = "2\DF";
    testBox.validate();
    if (testBox.getContent().cardsDecks !== 10 || testBox.getContent().cardsDeckWaitExponent !== 5 || testBox.getContent().cardsRepetitionsPerDeck !== 2) {
        return false;
    }
    testBox.getContent().cardsRepetitionsPerDeck = "29";
    testBox.getContent().private_sortColumn = -5;
    testBox.validate();
    if (testBox.getContent().cardsRepetitionsPerDeck !== 10 || testBox.getContent().private_sortColumn !== 0) {
        return false;
    }
    testBox.getContent().private_sortColumn = 10;
    testBox.validate();
    if (testBox.getContent().private_sortColumn !== 9) {
        return false;
    }
    return true;
}
function test_box_validate() {
    var testBox = new Box();
    testBox.content.cards.push(test_card_00);
    test_card_00.setContent(["1528468538430", "aa aa", "bb bb", "cc cc", "Dd aa", "1528468538430", "0", "0", "", "1528468591486", false]);
    testBox.validate();
    if (test_card_00.content[0] !== 1528468538430) {
        return false;
    }
    if (testBox.content.cards[0].content[0] !== 1528468538430) {
        return false;
    }
    if (test_card_00.content[0] === "1528468538430") {
        return false;
    }
    
    if (testBox.content.private_block !== false) {
        return false;
    }

    testBox.content.private_sortReverse = true;
    testBox.content.private_switch_learn_direction = false;
    testBox.content.private_switch_learn_all = true;
    testBox.content.private_autosave = true;
    testBox.content.private_show_card_sort = true;
    testBox.content.private_sort_default = true;
    testBox.content.private_search_convenient = true;
    testBox.content.private_block = true;
    testBox.validate();
    if (testBox.content.private_sortReverse !== true) {
        return false;
    }
    if (testBox.content.private_switch_learn_direction !== false) {
        return false;
    }
    if (testBox.content.private_switch_learn_all !== true) {
        return false;
    }
    if (testBox.content.private_autosave !== true) {
        return false;
    }
    if (testBox.content.private_autosave !== true) {
        return false;
    }
    if (testBox.content.private_show_card_sort !== true) {
        return false;
    }
    if (testBox.content.private_sort_default !== true) {
        return false;
    }
    if (testBox.content.private_search_convenient !== true) {
        return false;
    }
    if (testBox.content.private_block !== true) {
        return false;
    }
    testBox.content.private_sortReverse = "true";
    testBox.content.private_switch_learn_direction = "false";
    testBox.content.private_switch_learn_all = "true";
    testBox.content.private_autosave = "true";
    testBox.content.private_show_card_sort = "true";
    testBox.content.private_sort_default = "true";
    testBox.content.private_search_convenient = "true";
    testBox.content.private_block = "true";
    testBox.validate();
    if (testBox.content.private_sortReverse !== true) {
        return false;
    }
    if (testBox.content.private_switch_learn_direction !== false) {
        return false;
    }
    if (testBox.content.private_switch_learn_all !== true) {
        return false;
    }
    if (testBox.content.private_autosave !== true) {
        return false;
    }
    if (testBox.content.private_show_card_sort !== true) {
        return false;
    }
    if (testBox.content.private_sort_default !== true) {
        return false;
    }
    if (testBox.content.private_search_convenient !== true) {
        return false;
    }
    if (testBox.content.private_block !== true) {
        return false;
    }
    testBox.content.private_sortReverse = "";
    testBox.content.private_switch_learn_direction = 0;
    testBox.content.private_switch_learn_all = "hallo";
    testBox.content.private_autosave = "hallo";
    testBox.content.private_show_card_sort = "hallo";
    testBox.content.private_sort_default = "hallo";
    testBox.content.private_search_convenient = "hallo";
    testBox.content.private_block = "nonsense";
    testBox.validate();
    if (testBox.content.private_sortReverse !== false) {
        return false;
    }
    if (testBox.content.private_switch_learn_direction !== false) {
        return false;
    }
    if (testBox.content.private_switch_learn_all !== false) {
        return false;
    }
    if (testBox.content.private_autosave !== true) {
        return false;
    }
    if (testBox.content.private_show_card_sort !== false) {
        return false;
    }
    if (testBox.content.private_sort_default !== true) {
        return false;
    }
    if (testBox.content.private_search_convenient !== true) {
        return false;
    }
    if (testBox.content.private_block !== false) {
        return false;
    }
    testBox.content.private_visibleColumns = ["", 0, 1, "hallo", "false", false, false, false, false, false, false];
    testBox.validate();
    if (testBox.content.private_visibleColumns[0] !== false || testBox.content.private_visibleColumns[1] !== true
        || testBox.content.private_visibleColumns[2] !== true || testBox.content.private_visibleColumns[3] !== false) {
        return false;
    }
    return true;
}
function test_box_sortBy() {
    logger.log("Run test_box_sortBy()...");
    box = new Box();
    box.setTitle("A title");
    box.setDescription("A description");
    test_card_00.setContent(["1528468538430", "aa aa", "bb bb", "cc cc", "Dd aa", "1528468538430", "0", "0", "", "1528468591486", false]);
    test_card_01.setContent(["1528468567982", "bb bb", "cc cc", "aa aa", "dd bb", "1528468567982", "0", "0", "0", "1528468591486", false]);
    test_card_02.setContent(["1528468591486", "cc cc", "aa aa", "bb bb", "Dd cc", "1528468591486", "0", "0", "0", "1528468591486", false]);
    box.getContent().cards.push(test_card_00);
    box.getContent().cards.push(test_card_01);
    box.getContent().cards.push(test_card_02);
    box.sortBy(0, false);
    // column
    if (box.getContent().cards[0] != test_card_00 || box.getContent().cards[1] != test_card_01 || box.getContent().cards[2] != test_card_02) {
        return false;
    }
    box.sortBy(2, false);
    if (box.getContent().cards[0] != test_card_02 || box.getContent().cards[1] != test_card_00 || box.getContent().cards[2] != test_card_01) {
        return false;
    }
    // reverse order
    box.sortBy(0, true);
    if (box.getContent().cards[0] != test_card_02 || box.getContent().cards[1] != test_card_01 || box.getContent().cards[2] != test_card_00) {
        return false;
    }
    // lower case
    box.sortBy(4, false);
    if (box.getContent().cards[0] != test_card_00 || box.getContent().cards[1] != test_card_01 || box.getContent().cards[2] != test_card_02) {
        return false;
    }
    box.sortBy(4, true);
    if (box.getContent().cards[0] != test_card_02 || box.getContent().cards[1] != test_card_01 || box.getContent().cards[2] != test_card_00) {
        return false;
    }
    return true;
}
function test_box_getCardsArrayFiltered() {
    logger.log("Run test_box_getCardsArrayFiltered()...");
    box = new Box();
    box.setTitle("A title");
    box.setDescription("A description");
    box.getContent().cards.push(test_card_00);
    box.getContent().cards.push(test_card_01);
    box.getContent().cards.push(test_card_02);

    var filteredCards = box.getCardsArrayFiltered(["", "", "", " ", "", ""]);
    if (filteredCards.length != 3) {
        return false;
    }

    filteredCards = box.getCardsArrayFiltered(["", "", "", " ", "", "", "0"]);
    if (filteredCards.length != 3) {
        return false;
    }
    // to use more columns AND search with operator AND
    filteredCards = box.getCardsArrayFiltered(["", "cc", "a a"]);
    if (filteredCards.length != 1 || filteredCards[0] != test_card_02) {
        return false;
    }
    // to use to or more blanks and AND extra column that is ignored (to speed
    // up) AND test case insensitiv search by DD
    filteredCards = box.getCardsArrayFiltered(["", "c", "a a", "", "DD"]);
    if (filteredCards.length != 1 || filteredCards[0] != test_card_02) {
        return false;
    }
    // to use AND in a column to exclude a card
    filteredCards = box.getCardsArrayFiltered(["", "c", "a bb", "", "DD"]);
    if (filteredCards.length != 0) {
        return false;
    }
    // filter longer than value in card
    filteredCards = box.getCardsArrayFiltered(["", "c", "a aaa", "", "DD"]);
    if (filteredCards.length != 0) {
        return false;
    }
    // -- convenient filter --
    box.search = "y"
    var filteredCards = box.getCardsArrayFiltered(["", "", "", " ", "", ""]);
    if (filteredCards.length !== 0) {
        return false;
    }
    if (JSON.stringify(box.searchResultColumns) !== JSON.stringify([false, true, true, false, false, false, false, false, false, false, false])) {
        return false;
    }
    box.search = "dd"
    var filteredCards = box.getCardsArrayFiltered(["", "", "", " ", "", ""]);
    if (filteredCards.length !== 3) {
        return false;
    }
    if (JSON.stringify(box.searchResultColumns) !== JSON.stringify([false, true, true, false, true, false, false, false, false, false, false])) {
        return false;
    }
    // to use more columns AND search with operator AND -> but this is overwritten by the convenient search
    filteredCards = box.getCardsArrayFiltered(["", "cc", "a a"]);
    if (filteredCards.length !== 3) {
        return false;
    }
    if (JSON.stringify(box.searchResultColumns) !== JSON.stringify([false, true, true, false, true, false, false, false, false, false, false])) {
        return false;
    }
    box.search = "Aa dd bB"
    var filteredCards = box.getCardsArrayFiltered(["", "", "", " ", "", ""]);
    if (filteredCards.length !== 3) {
        return false;
    }
    if (JSON.stringify(box.searchResultColumns) !== JSON.stringify([false, true, true, true, true, false, false, false, false, false, false])) {
        return false;
    }
    box.search = "15"
    var filteredCards = box.getCardsArrayFiltered(["", "", "", " ", "", ""]);
    if (filteredCards.length !== 0) {
        return false;
    }
    if (JSON.stringify(box.searchResultColumns) !== JSON.stringify([false, true, true, false, false, false, false, false, false, false, false])) {
        return false;
    }
    box.search = "Aa"
    var filteredCards = box.getCardsArrayFiltered(["", "", "", " ", "", ""]);
    if (filteredCards.length !== 3) {
        return false;
    }
    if (JSON.stringify(box.searchResultColumns) !== JSON.stringify([false, true, true, true, true, false, false, false, false, false, false])) {
        return false;
    }
    box.search = ""
    filteredCards = box.getCardsArrayFiltered(["", "c", "a a", "", "DD"]);
    if (filteredCards.length != 1 || filteredCards[0] != test_card_02) {
        return false;
    }
    if (JSON.stringify(box.searchResultColumns) !== JSON.stringify([false, false, false, false, false, false, false, false, false, false, false])) {
        return false;
    }
    return true;
}
function test_card_isDue() {
    logger.log("Run test_card_isDue()...");
    var card = new Card();
    if (!card.isDue(0)) {
        return false;
    }
    // Debug some invalid values
    card.getContent()[6] = "";
    card.getContent()[7] = "";
    card.getContent()[8] = "";
    card.getContent()[9] = "";
    if (!card.isDue(0) || card.getContent()[6] != 0 || card.getContent()[7] != 0 || card.getContent()[8] != 0 || card.getContent()[9] != 0) {
        return false;
    }
    card.getContent()[6] = ">/";
    card.getContent()[7] = ">/";
    card.getContent()[8] = ">/";
    card.getContent()[9] = ">/";
    if (!card.isDue(0) || card.getContent()[6] != 0 || card.getContent()[7] != 0 || card.getContent()[8] != 0 || card.getContent()[9] != 0) {
        return false;
    }
    card.getContent()[6] = "-1";
    card.getContent()[7] = "-1";
    card.getContent()[8] = "-1";
    if (!card.isDue(0) || card.getContent()[6] != 0 || card.getContent()[7] != 0 || card.getContent()[8] != 0) {
        return false;
    }
    card.getContent()[6] = "1";
    card.getContent()[7] = "1";
    card.getContent()[8] = "5";
    if (!card.isDue(0) || card.getContent()[6] != 1 || card.getContent()[7] != 1 || card.getContent()[8] != 5) {
        return false;
    }
    card.getContent()[6] = 0;
    card.getContent()[7] = 0;
    card.getContent()[8] = 0;
    if (!card.isDue(0) || card.getContent()[6] != 0 || card.getContent()[7] != 0 || card.getContent()[8] != 0) {
        return false;
    }
    card.getContent()[6] = 6;
    card.getContent()[7] = 0;
    card.getContent()[8] = 5;
    if (!card.isDue(0) || card.getContent()[6] != 6 || card.getContent()[7] != 0 || card.getContent()[8] != 5) {
        return false;
    }
    card.getContent()[6] = 7;
    card.getContent()[7] = 3;
    if (!card.isDue(0) || card.getContent()[6] != 6 || card.getContent()[7] != 2) {
        return false;
    }
    card.getContent()[9] = new Date().getTime(); // last learned = now
    card.getContent()[6] = 0;
    card.getContent()[7] = 1;
    if (!card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 0;
    card.getContent()[7] = 2;
    if (!card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 0;
    card.getContent()[7] = 3;
    if (!card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 1;
    card.getContent()[7] = 0;
    if (card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 1;
    card.getContent()[7] = 0;
    if (card.isDue()) {
        return false;
    }
    card.getContent()[6] = 1;
    card.getContent()[7] = 1;
    if (!card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 1;
    card.getContent()[7] = 1;
    if (!card.isDue()) {
        return false;
    }
    card.getContent()[6] = 1;
    card.getContent()[7] = 2;
    if (!card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 1;
    card.getContent()[7] = 2;
    if (!card.isDue()) {
        return false;
    }
    card.getContent()[6] = 2;
    card.getContent()[7] = 0;
    if (card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 2;
    card.getContent()[7] = 0;
    if (card.isDue()) {
        return false;
    }
    card.getContent()[6] = 2;
    card.getContent()[7] = 1;
    if (card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 2;
    card.getContent()[7] = 1;
    if (card.isDue()) {
        return false;
    }
    card.getContent()[6] = 3;
    card.getContent()[7] = 0;
    if (card.isDue(0)) {
        return false;
    }
    card.getContent()[6] = 3;
    card.getContent()[7] = 0;
    if (card.isDue()) {
        return false;
    }
    card.getContent()[6] = 3;
    card.getContent()[7] = 1;
    if (card.isDue()) {
        return false;
    }
    card.getContent()[6] = 3;
    card.getContent()[7] = 1;
    if (card.isDue(0)) {
        return false;
    }
    timezoneOffsetMillisecondsOld = timezoneOffsetMilliseconds;
    timezoneOffsetMilliseconds = -120 * 60 * 1000;
    var card = new Card();
    var d = new Date('2015-06-10T09:00:00.000Z');
    var lastProgress = d.getTime();
    card.getContent()[6] = 1;
    card.getContent()[7] = 0;
    card.getContent()[9] = lastProgress;
    if (card.isDue(lastProgress)) {
        return false;
    }
    var testNow = new Date('2015-06-10T21:00:00.000Z');
    if (card.isDue(testNow.getTime())) {
        return false;
    }
    // after begin of new local day (local day = 2h before GMT)
    testNow = new Date('2015-06-10T23:00:00.000Z');
    if (!card.isDue(testNow.getTime())) {
        return false;
    }
    // 3 days wait time in deck 2
    card.getContent()[6] = 2;
    card.getContent()[7] = 0;
    testNow = new Date('2015-06-12T21:00:00.000Z');
    if (card.isDue(testNow.getTime())) {
        return false;
    }
    // no wait time for progress in deck = 1 and 2 (2.1, 2.2)
    card.getContent()[7] = 1;
    testNow = new Date('2015-06-12T21:00:00.000Z');
    if (!card.isDue(testNow.getTime())) {
        return false;
    }
    // after begin of local day
    testNow = new Date('2015-06-12T23:00:00.000Z');
    card.getContent()[7] = 0;
    if (!card.isDue(testNow.getTime())) {
        return false;
    }
    // deck 6
    card.getContent()[6] = 6;
    card.getContent()[7] = 0;
    testNow = new Date('2015-06-10T21:00:00.000Z');
    var dayMillisec = 1000 * 60 * 60 * 24;
    if (card.isDue(testNow.getTime() + 242 * dayMillisec)) {
        return false;
    }
    testNow = new Date('2015-06-10T23:00:00.000Z');
    if (!card.isDue(testNow.getTime() + 243 * dayMillisec)) {
        return false;
    }
    // 9 days wait time in deck 3
    // 8 days after last learned
    card.getContent()[6] = 3;
    card.getContent()[7] = 0;
    testNow = new Date('2015-06-18T21:00:00.000Z');
    if (card.isDue(testNow.getTime())) {
        return false;
    }
    // Same day and progress = 1
    card.getContent()[6] = 3;
    card.getContent()[7] = 1;
    testNow = new Date('2015-06-10T21:00:00.000Z');
    if (card.isDue(testNow.getTime())) {
        return false;
    }
    // next day but progress = 1
    testNow = new Date('2015-06-11T21:00:00.000Z');
    card.getContent()[7] = 1;
    if (card.isDue(testNow.getTime())) {
        return false;
    }
    // next day and progress = 1
    testNow = new Date('2015-06-19T21:00:00.000Z');
    card.getContent()[7] = 1;
    if (!card.isDue(testNow.getTime())) {
        return false;
    }
    // progress = 2
    testNow = new Date('2015-06-11T21:00:00.000Z');
    card.getContent()[7] = 2;
    if (card.isDue(testNow.getTime())) {
        return false;
    }
    // progress = 2
    testNow = new Date('2015-06-19T21:00:00.000Z');
    card.getContent()[7] = 2;
    if (!card.isDue(testNow.getTime())) {
        return false;
    }
    // just in case progress = 3
    testNow = new Date('2015-06-19T21:00:00.000Z');
    card.getContent()[7] = 3;
    if (!card.isDue(testNow.getTime())) {
        return false;
    }
    timezoneOffsetMilliseconds = timezoneOffsetMillisecondsOld;
    return true;
}
function test_card_move() {
    logger.log("Run test_card_move()...");
    box.getContent().cardsRepetitionsPerDeck = 3;
    var card = new Card();
    card.getContent()[6] = 0; // deck
    card.getContent()[7] = 0; // progress in deck
    card.getContent()[8] = 0; // learn count
    card.getContent()[9] = 0; // learn count
    card.move(true);
    if (card.getContent()[6] != 0 || card.getContent()[7] != 1 || card.getContent()[8] != 1 || card.getContent()[9] == 0) {
        return false;
    }
    card.move(false);
    if (card.getContent()[6] != 0 || card.getContent()[7] != 0 || card.getContent()[8] != 2) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 0 || card.getContent()[7] != 1) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 0 || card.getContent()[7] != 2) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 1 || card.getContent()[7] != 0) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 1 || card.getContent()[7] != 1) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 1 || card.getContent()[7] != 2) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 2 || card.getContent()[7] != 0) {
        return false;
    }
    box.getContent().cardsRepetitionsPerDeck = 2;
    card.move(true);
    if (card.getContent()[6] != 2 || card.getContent()[7] != 1) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 3 || card.getContent()[7] != 0) {
        return false;
    }
    box.getContent().cardsRepetitionsPerDeck = 1;
    card.move(true);
    if (card.getContent()[6] != 4 || card.getContent()[7] != 0) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 5 || card.getContent()[7] != 0) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 6 || card.getContent()[7] != 0) {
        return false;
    }
    card.move(true);
    if (card.getContent()[6] != 6 || card.getContent()[7] != 0) {
        return false;
    }

    timezoneOffsetMilliseconds = timezoneOffsetMillisecondsOld;
    return true;
}
function test_box_merge() {
    logger.log("Run test_box_merge()...");
    var localTestBox = new Box();
    localTestBox.getContent().boxID = "1528468531111";
    localTestBox.getContent().title = "local box";
    localTestBox.getContent().creator = "Genius";
    localTestBox.getContent().boxPublicID = "1528468533333";
    localTestBox.getContent().cardsDeckWaitExponent = 3;
    localTestBox.getContent().private_sortColumn = 1;
    localTestBox.getContent().lastChangedPublicMetaData = 1528468538430;
    localTestBox.getContent().lastChangedPrivateMetaData = 1528468538430;
    localTestBox.getContent().private_block = false;
    var remoteTestBox = localTestBox.getCopy();
    remoteTestBox.getContent().lastChangedPublicMetaData = 0;
    remoteTestBox.getContent().lastChangedPrivateMetaData = 0;
    remoteTestBox.getContent().boxID = "1528468532222";
    remoteTestBox.getContent().title = "remote box";
    remoteTestBox.getContent().creator = "another one";
    remoteTestBox.getContent().boxPublicID = "1528468533333";
    remoteTestBox.getContent().cardsDeckWaitExponent = 2;
    remoteTestBox.getContent().private_sortColumn = 2;
    remoteTestBox.getContent().private_block = true;
    // metadata local wins
    remoteTestBox.getContent().lastChangedPublicMetaData = "";
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().title != "local box") {
        return false;
    }
    if (localTestBox.getContent().private_block !== false) {
        return false;
    }
    // metadata local wins
    remoteTestBox.getContent().lastChangedPublicMetaData = 1528468538430;
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().title != "local box" || localTestBox.getContent().creator != "Genius") {
        return false;
    }
    if (localTestBox.getContent().cardsDeckWaitExponent != 3 || localTestBox.getContent().private_sortColumn != 1) {
        return false;
    }
    // inlude test for "changed" marker of box
    localTestBox.edit();
    localTestBox.getContent().title = "Changed title";
    localTestBox.getContent().description = "Changed description";
    localTestBox.save('box'); // mark the box as "changed"
    localTestBox.getContent().cardsDeckWaitExponent = 3;
    //localTestBox.getContent().lastChangedPublicMetaData = 1528468538430; // set back
    localTestBox.getContent().lastChangedPrivateMetaData = 1528468538430;
    // metadata remote wins but not over "cards...." and "private..."
    remoteTestBox.getContent().lastChangedPublicMetaData = 1528468538431;
    remoteTestBox.getContent().lastChangedPrivateMetaData = 1528468538431;
    remoteTestBox.getContent().cardsDeckWaitExponent = 2;
    localTestBox.merge(remoteTestBox);
    // local wins
    if (localTestBox.getContent().title != "Changed title" || localTestBox.getContent().description != "Changed description") {
        return false;
    }
    if (!localTestBox.content.private_hasChanged) {
        return false; // check "changed" marker
    }
    // local wins because different boxID
    if (localTestBox.getContent().cardsDeckWaitExponent != 3) {
        return false;
    }
    // Same boxID with
    remoteTestBox.getContent().boxID = "1528468531111";
    localTestBox.merge(remoteTestBox);
    // local wins (not changed)
    if (localTestBox.getContent().title != "Changed title" || localTestBox.getContent().description != "Changed description") {
        return false;
    }
    if (!localTestBox.content.private_hasChanged) {
        return false; // check "changed" marker
    }
    // remote wins because now same boxID
    if (localTestBox.getContent().cardsDeckWaitExponent != 2) {
        return false;
    }
    // inlude test for "changed" public marker of box
    localTestBox.edit();
    localTestBox.getContent().title = "xy"; // public meta data
    localTestBox.getContent().cardsDeckWaitExponent = 4; // private meta data
    localTestBox.save('box'); // mark the box as "changed"
    localTestBox.getContent().lastChangedPublicMetaData = 1528468538430;
    localTestBox.getContent().private_block = false;
    //localTestBox.getContent().lastChangedPrivateMetaData = 1528468538430; // set back
    // metadata remote wins but not over public metadata
    remoteTestBox.getContent().lastChangedPublicMetaData = 1528468538431;
    remoteTestBox.getContent().lastChangedPrivateMetaData = 1528468538431;
    remoteTestBox.getContent().title = "ab";
    remoteTestBox.getContent().private_block = "true";
    remoteTestBox.getContent().cardsDeckWaitExponent = 2; // private meta data
    localTestBox.merge(remoteTestBox);
    // remote wins public meta data
    if (localTestBox.getContent().title != "ab") {
        return false;
    }
    // local wins private meta data
    if (localTestBox.getContent().cardsDeckWaitExponent != 4) {
        return false;
    }
    if (!localTestBox.content.private_hasChanged) {
        return false;
    }
    if (localTestBox.getContent().private_block !== false) {
        return false;
    }
    // remote wins private and public metadata	
    localTestBox.edit();
    localTestBox.getContent().description = "ab"; // public meta data
    localTestBox.getContent().cardsDeckWaitExponent = 1; // private meta data
    localTestBox.save('box'); // mark the box as "changed"
    localTestBox.getContent().lastChangedPublicMetaData = 1528468538430;
    localTestBox.getContent().lastChangedPrivateMetaData = 1528468538430;
    // metadata remote wins but not over public metadata
    remoteTestBox.getContent().lastChangedPublicMetaData = 1528468538431;
    remoteTestBox.getContent().lastChangedPrivateMetaData = 1528468538431;
    remoteTestBox.getContent().title = "abc";
    localTestBox.getContent().cardsDeckWaitExponent = 2; // private meta data
    localTestBox.merge(remoteTestBox);
    // remote wins public meta data
    if (localTestBox.getContent().title != "abc") {
        return false;
    }
    // remote wins private meta data
    if (localTestBox.getContent().cardsDeckWaitExponent != 2) {
        return false;
    }
    if (localTestBox.getContent().private_block !== true) {
        return false;
    }
    if (localTestBox.content.private_hasChanged) {
        return false;
    }

    // public meta data remote wins AND is own box (overwrites "cards...", "private...")
    localTestBox.getContent().boxID = "1528468531111";
    localTestBox.getContent().title = "local box";
    localTestBox.getContent().cardsDeckWaitExponent = 3;
    localTestBox.getContent().private_sortColumn = 1;
    remoteTestBox.getContent().boxID = "1528468531111";
    remoteTestBox.getContent().title = "remote box";
    remoteTestBox.getContent().cardsDeckWaitExponent = 2;
    remoteTestBox.getContent().private_sortColumn = 4;
    remoteTestBox.getContent().lastChangedPublicMetaData = 1528468538432;
    remoteTestBox.getContent().lastChangedPrivateMetaData = 1528468538430;
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().title != "remote box" || localTestBox.getContent().creator != "Genius") {
        return false;
    }
    if (localTestBox.getContent().boxPublicID != "1528468533333") {
        return false; // this is one of the values that should never be overwritten
    }
    if (localTestBox.content.private_hasChanged) {
        return false; // check "changed" marker
    }
    // private metdata is not changed
    if (localTestBox.getContent().cardsDeckWaitExponent != 3 || localTestBox.getContent().private_sortColumn != 1) {
        return false;
    }
    // public meta data remote wins AND is own box (overwrites "cards...", "private...")
    localTestBox.getContent().boxID = "1528468531111";
    localTestBox.getContent().title = "local box";
    localTestBox.getContent().creator = "Genius";
    localTestBox.getContent().creator_xchan_hash = "Genius_obs";
    localTestBox.getContent().cardsDeckWaitExponent = 1;
    localTestBox.getContent().private_sortColumn = 5;
    localTestBox.getContent().lastChangedPublicMetaData = 1528468538430;
    localTestBox.getContent().lastChangedPrivateMetaData = 1528468538431;
    remoteTestBox.getContent().boxID = "1528468531111";
    remoteTestBox.getContent().title = "remote box";
    remoteTestBox.getContent().creator = "Would-be";
    remoteTestBox.getContent().creator_xchan_hash = "Would-be-obs";
    remoteTestBox.getContent().cardsDeckWaitExponent = 4;
    remoteTestBox.getContent().private_sortColumn = 6;
    remoteTestBox.getContent().lastChangedPublicMetaData = 1528468538431;
    remoteTestBox.getContent().lastChangedPrivateMetaData = 1528468538431;
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().title != "remote box" || localTestBox.getContent().creator != "Genius") {
        return false;
    }
    if (localTestBox.getContent().title != "remote box" || localTestBox.getContent().creator_xchan_hash != "Genius_obs") {
        return false;
    }
    if (localTestBox.getContent().boxPublicID != "1528468533333") {
        return false; // this is one of the values that should never be overwritten
    }
    // private metdata is not changed
    if (localTestBox.getContent().cardsDeckWaitExponent != 1 || localTestBox.getContent().private_sortColumn != 5) {
        return false;
    }
    // remote box has a new card
    var remoteTestCard_1 = new Card();
    remoteTestCard_1.setContent(["1528468591486", "cc cc", "aa aa", "bb bb", "Dd cc", "1528468591486", "0", "0", "0", "1528468590202"]);
    remoteTestCard_1.validate();
    remoteTestBox.getContent().cards.push(remoteTestCard_1);
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().cards.length != 1 || localTestBox.getContent().cards[0] != remoteTestCard_1) {
        return false;
    }
    //
    box.getContent().cardsRepetitionsPerDeck = 3;
    // local card has changed content
    var localTestCard_1 = new Card();
    localTestCard_1.setContent(["1528468591486", "cc 11", "aa 11", "bb 11", "Dd 11", "1528468591487", "1", "2", "3", "1528468590202"]);
    localTestCard_1.validate();
    localTestBox.getContent().cards = [];
    localTestBox.getContent().cards.push(localTestCard_1);
    // remote card has changed progress
    remoteTestCard_1.setContent(["1528468591486", "cc cc", "aa aa", "bb bb", "Dd cc", "1528468591486", "4", "2", "6", "1528468590203"]);
    remoteTestCard_1.validate();
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().cards.length != 1) {
        return false;
    }
    if (localTestCard_1.getContent()[1] !== "cc 11"
        || localTestCard_1.getContent()[2] !== "aa 11" || localTestCard_1.getContent()[3] !== "bb 11"
        || localTestCard_1.getContent()[4] !== "Dd 11") {
        return false;
    }
    if (localTestCard_1.getContent()[6] !== 4 || localTestCard_1.getContent()[7] !== 2
        || localTestCard_1.getContent()[8] !== 6) {
        return false;
    }
    // local card has changed progress
    localTestCard_1.setContent(["1528468591486", "cc 11", "aa 11", "bb 11", "Dd 11", "1528468591487", "5", "1", "33", "1528468590204", true]);
    localTestCard_1.validate();
    // remote card has changed content
    remoteTestCard_1.setContent(["1528468591486", "cc cc", "aa aa", "bb bb", "Dd cc", "1528468591488", "4", "2", "6", "1528468590203", false]);
    remoteTestCard_1.validate();
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().cards.length != 1) {
        return false;
    }
    if (!localTestCard_1.getContent()[10]) {
        return false; // marker "changed" should not be overwritten (
    }
    if (localTestCard_1.getContent()[1] !== "cc cc"
        || localTestCard_1.getContent()[2] !== "aa aa" || localTestCard_1.getContent()[3] !== "bb bb"
        || localTestCard_1.getContent()[4] !== "Dd cc") {
        return false;
    }
    if (localTestCard_1.getContent()[6] !== 5 || localTestCard_1.getContent()[7] !== 1
        || localTestCard_1.getContent()[8] !== 33) {
        return false;
    }
    // This is not the own box (!= boxID)
    // remote card wins content but learn progress is not overwritten
    remoteTestBox.getContent().boxID = "1528468532222";
    remoteTestCard_1.setContent(["1528468591486", "cc xx", "aa xx", "bb xx", "Dd xx", "1528468591489", "4", "2", "6", "1528468590205", false]);
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().cards.length != 1) {
        return false;
    }
    if (!localTestCard_1.getContent()[10]) {
        return false; // marker "changed" should not be overwritten (
    }
    if (localTestCard_1.getContent()[1] !== "cc xx"
        || localTestCard_1.getContent()[2] !== "aa xx" || localTestCard_1.getContent()[3] !== "bb xx"
        || localTestCard_1.getContent()[4] !== "Dd xx") {
        return false;
    }
    if (localTestCard_1.getContent()[6] !== 5 || localTestCard_1.getContent()[7] !== 1
        || localTestCard_1.getContent()[8] !== 33) {
        return false;
    }
    // Box not owned but has new card. Do not get the learn progress
    // remote box has a new card
    var remoteTestCard_2 = new Card();
    remoteTestCard_2.setContent(["1528468591487", "11", "22", "33", "44", "1528468591480", "1", "2", "3", "1528468590202", true]);
    remoteTestCard_2.validate();
    remoteTestBox.getContent().cards.push(remoteTestCard_2);
    localTestBox.merge(remoteTestBox);
    if (localTestBox.getContent().cards.length != 2) {
        return false;
    }
    var localTestCard_2 = localTestBox.getCard(1528468591487);
    if (localTestCard_2.getContent()[10]) {
        return false; // nothing changed because imported
    }
    if (localTestCard_2.getContent()[1] !== "11"
        || localTestCard_2.getContent()[2] !== "22" || localTestCard_2.getContent()[3] !== "33"
        || localTestCard_2.getContent()[4] !== "44") {
        return false;
    }
    if (localTestCard_2.getContent()[6] !== 0 || localTestCard_2.getContent()[7] !== 0
        || localTestCard_2.getContent()[8] !== 0 || localTestCard_2.getContent()[9] !== 0) {
        return false;
    }
    return true;
}

function loadBox() {
    boxLocalStore.check();
    box.load();
    box.validate();
    postUrl = $("#flashcards_post_url").html();
    nick = $("#flashcards_nick").html();
    is_owner = $("#flashcards_is_owner").html();
    is_local_channel = $("#flashcards_is_local_channel").html();
    is_allowed_to_create_box = $("#flashcards_is_allowed_to_create_box").html();
    if (!is_allowed_to_create_box) {
        $("#flashcards_new_box").hide();
        $("#flashcards-block-changes-row").hide();
    }
    flashcards_editor = $("#flashcards_editor").html();
    if (box.isEmpty()) {
        logger.log('The box was not stored in local storage of browser. Try to load box from URL...');
        if (downLoadBoxForURL()) {
            return;
        }
    }
    // Check if the boxID is different in URL
    var pathname = window.location.pathname;
    var pathnameArr = pathname.split('/');
    var href = window.location.href;
    logger.log('href = ' + href);
    var hrefArr = href.split('/');
    if (pathnameArr.length == 4 && box.content.boxID !== "" && pathnameArr[3] !== "") {
        logger.log('Both have a boxID: 1. local storage boxID = ' + box.content.boxID + ', 2. URL boxID = ' + hrefArr[6]);
        if (box.content.boxID !== hrefArr[5]) {
            logger.log('BoxID from local storage is not the boxID in the URL. Loading box from URL...');
            if (downLoadBoxForURL()) {
                return;
            }
        }
        if (box.content.boxID == pathnameArr[3]) {
            logger.log('BoxID from local storage is the same as in URL...');
            loadStartPage();
            return;
        }
    }
    if (pathnameArr.length < 4 && box.content.boxID !== "") {
        logger.log('URL (' + href + ') has only 5 elements and probably does not contain the boxID. Found in local storage boxID = ' + box.content.boxID);
        logger.log('BoxID from local storage is not the boxID in the URL. Loading box from URL...');
        var url = postUrl + '/' + box.content.boxID;
        logger.log('Redirecting to URL... ' + url);
        window.location.assign(url);
    }
    loadCloudBoxes();
//    redirectToAppRoot();
}

/*
 * Approach
 * Check local storage for a box
 * - If found load the box
 * - If not found look for a boxID in the URL and try to load
 * 
 * this is a stupid test
 */
$(document).ready(function () {
    // Logging on/off
    // logger.disableLogger();
    logger.enableLogger();
    logger.log('Loading FlashCards...');
    timezoneOffsetMilliseconds = new Date().getTimezoneOffset() * 1000 * 60;
    logger.log('Timezone offset is: ' + timezoneOffsetMilliseconds / 1000 / 60 + ' min');
    loadBox();
});
