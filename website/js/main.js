import { helpers } from './helpers.js?v=20241001';
import { api } from './api.js?v=20241001';

let presentPlayers = [];
let rankingData = [];
let allPlayers = [];
let drawnOutPlayers = [];
let matches = [];
let roundId = 0;
const addResultModalHtml = document.getElementById('addResultModal');
const addResultModal = new mdb.Modal(addResultModalHtml);

const addPlayerModalHtml = document.getElementById('addPlayerModal');
const addPlayerModal = new mdb.Modal(addPlayerModalHtml);

loadData();

document.getElementById('addPlayerModal').addEventListener('show.bs.modal', function () {
    // clear input fields
    document.getElementById('addPlayer_playerFirstName').value = '';
    document.getElementById('addPlayer_playerName').value = '';
    document.getElementById('addPlayer_playerBirthdate').value = '';
    document.getElementById('addPlayer_genderMale').checked = true;
    document.getElementById('addPlayer_genderFemale').checked = false;
    document.getElementById('addPlayer_playerCompetitor').checked = false;
    document.getElementById('addPlayer_doubleRankingDiv').style.display = 'none';
    document.getElementById('addPlayer_doubleRanking').value = '';
});

document.getElementById('generateMatchesButton').addEventListener('click', generateMatches);
document.getElementById('togglePlayerListButton').addEventListener('click', togglePlayerList);
document.getElementById('calculateRankingButton').addEventListener('click', calculateRanking);
document.getElementById('addPlayerButton').addEventListener('click', createPlayer);

document.getElementById('addPlayer_playerCompetitor').addEventListener('click', toggleRankingInput);

function loadData() {
    const requestFetchAllPlayers = fetch('api/index.php/players');
    const requestFetchRanking = fetch('api/index.php/rankings/general');
    const requestFetchRound = fetch('api/index.php/rounds/latest');

    Promise.all([requestFetchAllPlayers, requestFetchRanking, requestFetchRound])
        .then(responses => {
            return Promise.all(responses.map(response => response.json()));
        })
        .then(data => {
            const data1 = data[0];
            rankingData = data[1].general;
            const roundData = data[2];
            roundId = roundData.id;
            allPlayers = data1;

            let manualRank = rankingData.length + 1;
            //enrich allPlayers with rank
            allPlayers.forEach((player) => {
                const rank = rankingData.find(rankedPlayer => rankedPlayer.id === player.id);
                if (rank) {
                    player.rank = rank.rank;
                }
                else {
                    player.rank = manualRank;
                    manualRank++;
                }
            });

            if (roundData.calculated == 1) {
                //TODO: Show new round popup
            }
            helpers.updateRoundSpan(roundData.number);
            if (roundData.availabilityData) {
                roundData.availabilityData.forEach((playerPresentData) => {
                    if (playerPresentData.present == 1) {
                        //find player in allPlayers
                        const player = allPlayers.find(player => player.id === playerPresentData.playerId);
                        //add player to presentPlayers
                        presentPlayers.push(player);
                    }
                });

                helpers.updateAmountPlayersSpan(presentPlayers.length);
            }
            if (roundData.matches) {
                roundData.matches.forEach((match) => {
                    matches.push(helpers.mapRoundMatch(match, allPlayers));
                });
            }
            //sort allPlayers on firstName, then on name
            allPlayers.sort((a, b) => (a.firstName > b.firstName) ? 1 : (a.firstName === b.firstName) ? ((a.name > b.name) ? 1 : -1) : -1);

            allPlayers.forEach((item, index) => {
                const player = allPlayers.find(player => player.id === item.id);
                let isPresent = false;
                //check if player is present
                presentPlayers.forEach(presentPlayer => {
                    if (presentPlayer.id == player.id) {
                        isPresent = true;
                    }
                });

                const name = item.firstName + ' ' + item.name + ' (' + helpers.calculateBonusPoints(player) + ')';
                const points = item.points;

                const rankElement = document.createElement('div');
                rankElement.classList.add('grid-item');
                rankElement.textContent = "Ranking: " + item.rank;

                const nameElement = document.createElement('div');
                nameElement.classList.add('grid-item');
                nameElement.textContent = name;

                const pointsElement = document.createElement('div');
                pointsElement.classList.add('grid-item');
                pointsElement.textContent = points;


                const button = document.createElement("button");
                button.classList.add('btn', isPresent ? 'btn-success' : 'btn-primary', 'btn-lg', 'me-3');
                const icon = document.createElement('i');
                icon.classList.add('fa', isPresent ? 'fa-check' : 'fa-plus');
                button.appendChild(icon);
                if (isPresent) {
                    button.onclick = function () {
                        removePlayerPresent(item.id);
                        updateAbsentButton(button, item.id);
                    };
                }
                else {
                    button.onclick = function () {
                        addPlayerPresent(item.id);
                        updatePresentButton(button, item.id);
                    };
                }
                pointsElement.appendChild(button);
                const label = document.createElement('span');
                label.classList.add('badge', isPresent ? 'badge-success' : 'badge-primary');
                label.textContent = isPresent ? 'Aangemeld!' : 'Nog niet aangemeld';
                pointsElement.appendChild(label);

                document.querySelector('.grid-container').appendChild(rankElement);
                document.querySelector('.grid-container').appendChild(nameElement);
                document.querySelector('.grid-container').appendChild(pointsElement);
            });

            if (matches.length > 0) {
                displayMatches(matches);
            }
        })
        .catch(error => console.error(error));
}

function addPlayerPresent(id) {
    //find player in allPlayers
    const player = allPlayers.find(player => player.id === id);
    //add player to presentPlayers
    presentPlayers.push(player);
    helpers.updateAmountPlayersSpan(presentPlayers.length);
    // POST to /api/index.php/rounds/{roundId}/players/{playerId}
    api.updateAvailabilityApi(roundId, player.id, true);
}

function updatePresentButton(button, id) {
    var span = button.nextSibling;
    span.textContent = 'Aangemeld!';
    span.classList.remove('badge-primary');
    span.classList.add('badge-success');

    button.classList.remove('btn-primary');
    button.classList.add('btn-success');
    //update icon inside
    var icon = button.firstChild;
    icon.classList.remove('fa-plus');
    icon.classList.add('fa-check');

    button.appendChild(icon);
    button.onclick = function () {
        removePlayerPresent(id);
        updateAbsentButton(button, id);
    };

}
function updateAbsentButton(button, id) {
    var span = button.nextSibling;
    span.textContent = 'Nog niet aangemeld';
    span.classList.remove('badge-success');
    span.classList.add('badge-primary');
    button.classList.remove('btn-success');
    button.classList.add('btn-primary');
    var icon = button.firstChild;
    icon.classList.remove('fa-check');
    icon.classList.add('fa-plus');
    button.onclick = function () {
        addPlayerPresent(id);
        updatePresentButton(button, id);
    };
}
function removePlayerPresent(id) {
    //find player in presentPlayers
    const player = presentPlayers.find(player => player.id === id);
    //remove player from presentPlayers
    presentPlayers.splice(presentPlayers.indexOf(player), 1);
    helpers.updateAmountPlayersSpan(presentPlayers.length);
    api.updateAvailabilityApi(roundId, player.id, false);
}


function generateMatches() {
    // Sort presentPlayers by rank
    presentPlayers.sort((a, b) => (a.rank > b.rank) ? 1 : -1);
    // Create two groups, which consist of 60% of the players
    // Overlap between those two groups is 20%
    const firstGroup = presentPlayers.slice(0, Math.floor(presentPlayers.length * 0.60));
    const secondGroup = presentPlayers.slice(Math.floor(presentPlayers.length * 0.40), presentPlayers.length);
    // Create matches
    matches = [];

    // Create match with 4 random players from first group
    // Then match with 4 random players from second group
    // Repeat until all players are matched (or less than 4 players left)
    // Don't forget to remove matched players from the groups

    while (firstGroup.length >= 4 || secondGroup.length >= 4) {
        if (firstGroup.length >= 4) {
            const match = [];
            match["players"] = [];
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * firstGroup.length);
                match["players"].push(firstGroup[randomIndex]);
                firstGroup.splice(randomIndex, 1);
            }
            matches.push(match);
            // remove matched players from second group
            match["players"].forEach(player => {
                const index = secondGroup.indexOf(player);
                if (index > -1) {
                    secondGroup.splice(index, 1);
                }
            });
        }
        if (secondGroup.length >= 4) {
            const match2 = [];
            match2["players"] = [];
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * secondGroup.length);
                match2["players"].push(secondGroup[randomIndex]);
                secondGroup.splice(randomIndex, 1);
            }
            matches.push(match2);
            // remove matched players from first group
            match2["players"].forEach(player => {
                const index = firstGroup.indexOf(player);
                if (index > -1) {
                    firstGroup.splice(index, 1);
                }
            });
        }
    }

    // If there are still players left, create a match with them
    if (firstGroup.length > 0 || secondGroup.length > 0) {
        // combine the two groups, but don't add the same player twice
        const remainingPlayers = firstGroup.concat(secondGroup).reduce((acc, current) => {
            const x = acc.find(item => item.id === current.id);
            if (!x) {
                return acc.concat([current]);
            } else {
                return acc;
            }
        }, []);

        // If there are more than 4 players left, pick 4 random players
        // Else pick all remaining players
        if (remainingPlayers.length >= 4) {
            const remainingPlayersMatch = [];
            remainingPlayersMatch["players"] = [];
            // Pick 4 random players from the remaining players
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * remainingPlayers.length);
                remainingPlayersMatch["players"].push(remainingPlayers[randomIndex]);
                remainingPlayers.splice(randomIndex, 1);
            }
            matches.push(remainingPlayersMatch);
        }
        if (remainingPlayers.length > 0) {
            // put all remaining players in the match
            const drawnOutMatch = [];
            drawnOutMatch["players"] = [];
            remainingPlayers.forEach(player => {
                drawnOutMatch["players"].push(player);
            });
            matches.push(drawnOutMatch);
            drawnOutPlayers = remainingPlayers;
        }
    }

    displayMatches(matches);
}
function displayMatches(matches) {
    // sort players now on name
    allPlayers.sort((a, b) => (a.firstName > b.firstName) ? 1 : -1);
    generateMatchesHtml(matches);

    // hide playerlist
    document.getElementById('playerList').style.display = 'none';
    document.getElementById('matchList').style.display = '';
    // show toggle button
    document.getElementById('togglePlayerListButton').style.display = '';
    document.getElementById('togglePlayerListButton').textContent = 'Toon spelerslijst';
    document.getElementById('calculateRankingButton').style.display = '';
}

function generateMatchesHtml(matches) {
    // display matches
    const matchesContainer = document.getElementById('matchList');
    matchesContainer.innerHTML = '';
    matches.forEach((match, index) => {
        // create match container
        const firstTeamContainer = document.createElement('div');
        firstTeamContainer.classList.add('grid-item');
        // add first two players to match container
        displayPlayer(match["players"][0], firstTeamContainer);
        if (match["players"].length > 1)
            displayPlayer(match["players"][1], firstTeamContainer);
        else {
            displayPlayerDropdown(match, firstTeamContainer, 1);
        }
        matchesContainer.appendChild(firstTeamContainer);
        // create second team container
        const secondTeamContainer = document.createElement('div');
        secondTeamContainer.classList.add('grid-item');
        // add second two players to match container
        if (match["players"].length > 2)
            displayPlayer(match["players"][2], secondTeamContainer);
        else {
            displayPlayerDropdown(match, secondTeamContainer, 2);
        }
        if (match["players"].length > 3)
            displayPlayer(match["players"][3], secondTeamContainer);
        else {
            displayPlayerDropdown(match, secondTeamContainer, 3);
        }
        matchesContainer.appendChild(secondTeamContainer);

        // add results div to match container
        const resultDiv = document.createElement('div');
        resultDiv.classList.add('grid-item');
        //add button to result div
        const button = document.createElement("button");
        resultDiv.appendChild(button);
        matchesContainer.appendChild(resultDiv);
        if (match["id"]) {
            generateUpdateMatchHtml(button, match);
        }
        else {
            //clear classList
            while (button.classList.length > 0) {
                button.classList.remove(button.classList.item(0));
            }
            button.classList.add('btn', 'btn-success', 'btn-lg', 'me-3');
            button.innerHTML = '<i class="fa fa-check me-1"></i> Bevestig match';
            button.onclick = function () {
                const countNonEmptySpots = match["players"].reduce((count, obj) => {
                    if (obj !== undefined) {
                        return count + 1;
                    } else {
                        return count;
                    }
                }, 0);

                if (countNonEmptySpots < 4) {
                    showErrorModal(['Gelieve alle spelers in te vullen.']);
                    return;
                }
                //if any of those players are in drawnOutPlayers, call api.updateAvailabilityApi with drawnOut = true
                match["players"].forEach(player => {
                    drawnOutPlayers.forEach(drawnOutPlayer => {
                        if (player.id == drawnOutPlayer.id) {
                            api.updateAvailabilityApi(roundId, player.id, true, true);
                        }
                    });
                });
                api.createMatch(roundId, match, button, onSuccessCreateMatch);
            };
        }
    });
}

function onSuccessCreateMatch(data, match, button) {
    match["id"] = data.id;
    generateUpdateMatchHtml(button, match);
}

function generateUpdateMatchHtml(button, match) {
    // once you start confirming matches: hide generate button
    document.getElementById('generateMatchesButton').style.display = 'none';
    // add sibling p next to button
    const p = document.createElement('p');
    // set id of p to matchId
    p.id = 'match-' + match.id;
    if (match["set1Home"]) {
        helpers.showMatchResult(p, match);
    }
    button.parentNode.insertBefore(p, button.nextSibling);
    //clear classList
    while (button.classList.length > 0) {
        button.classList.remove(button.classList.item(0));
    }
    // set id of button to button-match-{matchId}
    button.id = 'button-match-' + match.id;
    button.classList.add('btn', 'btn-primary', 'btn-lg', 'me-3');
    button.innerHTML = '<i class="fa fa-pencil me-1"></i> Vul resultaat in';
    button.onclick = function () {
        helpers.displayDataInModal(match);
        //show modal
        addResultModal.show();
        //get save button
        const saveButton = document.getElementById('saveMatchButton');
        saveButton.onclick = function () {
            //Get value of every input
            const set1Home = helpers.getScoreInputValue('set1ScoreHome');
            const set1Away = helpers.getScoreInputValue('set1ScoreAway');
            const set2Home = helpers.getScoreInputValue('set2ScoreHome');
            const set2Away = helpers.getScoreInputValue('set2ScoreAway');
            const set3Home = helpers.getScoreInputValue('set3ScoreHome');
            const set3Away = helpers.getScoreInputValue('set3ScoreAway');
            api.updateMatch(roundId, set1Home, set1Away, set2Home, set2Away, set3Home, set3Away, match, onSuccessUpdateMatch);
        }
    }
}

function onSuccessUpdateMatch(set1Home, set1Away, set2Home, set2Away, set3Home, set3Away, match) {
    addResultModal.hide();
    match["set1Home"] = set1Home;
    match["set1Away"] = set1Away;
    match["set2Home"] = set2Home;
    match["set2Away"] = set2Away;
    match["set3Home"] = set3Home;
    match["set3Away"] = set3Away;
    // display result
    const p = document.getElementById('match-' + match["id"]);
    helpers.showMatchResult(p, match);
}

function displayPlayer(player, container) {
    const playerElement = document.createElement('p');
    playerElement.textContent = player.firstName + ' ' + player.name + ' (' + helpers.calculateBonusPoints(player) + ')';
    container.appendChild(playerElement);
}
function displayPlayerDropdown(match, container, index) {
    const dropdownElement = document.createElement('p');
    const dropdown = document.createElement('select');
    //set width to 75%
    dropdown.style.width = '75%';
    dropdown.innerHTML = '';

    allPlayers.forEach((player, index) => {
        const option = document.createElement('option');
        option.value = player.id;
        option.textContent = player.firstName + ' ' + player.name + ' (' + helpers.calculateBonusPoints(player) + ')';
        dropdown.appendChild(option);
    });
    dropdownElement.appendChild(dropdown);

    //Add Button
    const button = document.createElement("button");
    button.classList.add('btn', 'btn-primary', 'btn-lg', 'ms-3');
    button.innerHTML = '<i class="fa fa-check me-1"></i>';
    button.onclick = function () {
        //find player in allPlayers
        const player = allPlayers.find(player => player.id === dropdown.value);
        api.updateAvailabilityApi(roundId, player.id, true);

        match["players"][index] = player;
        //remove dropdownElement from container
        container.removeChild(dropdownElement);
        displayPlayer(player, container);
    };
    dropdownElement.appendChild(button);
    container.appendChild(dropdownElement);
}

function toggleRankingInput() {
    const rankingInput = document.getElementById('addPlayer_doubleRankingDiv');
    const isCompetitor = document.getElementById('addPlayer_playerCompetitor').checked;
    if (rankingInput.style.display === 'none' && isCompetitor) {
        rankingInput.style.display = '';
        //change button text
    } else if (!isCompetitor) {
        rankingInput.style.display = 'none';
    }
}

function togglePlayerList() {
    const playerList = document.getElementById('playerList');
    if (playerList.style.display === 'none') {
        playerList.style.display = '';
        //change button text
        document.getElementById('togglePlayerListButton').textContent = 'Verberg spelerslijst';
        document.getElementById('matchList').style.display = 'none';
    } else {
        playerList.style.display = 'none';
        //change button text
        document.getElementById('togglePlayerListButton').textContent = 'Toon spelerslijst';
        document.getElementById('matchList').style.display = '';
    }
}

function calculateRanking() {
    api.calculateRanking(onSuccessGenerateRanking);
}

function onSuccessGenerateRanking() {
    //show success in infoModal
    helpers.showInfoModal('De ranking is berekend!');
}

function createPlayer() {
    const firstName = document.getElementById('addPlayer_playerFirstName').value;
    const name = document.getElementById('addPlayer_playerName').value;
    const birthDate = document.getElementById('addPlayer_playerBirthdate').value;
    const gender = document.getElementById('addPlayer_genderMale').checked ? "Man" : "Woman";
    const playsCompetition = document.getElementById('addPlayer_playerCompetitor').checked;
    const doubleRanking = playsCompetition ? document.getElementById('addPlayer_doubleRanking').value : 0;
    const basePoints = 19;
    api.createPlayer(firstName, name, gender, birthDate, doubleRanking, playsCompetition, basePoints, onSuccessAddPlayer);

}

function onSuccessAddPlayer() {
    addPlayerModal.hide();
    //show success in infoModal
    helpers.showInfoModal('Speler toegevoegd! Herlaad de pagina om de speler te zien.');
}
