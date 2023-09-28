const helpers = {
    displayDataInModal: function (match) {
        helpers.displayPlayerInModal(match["players"][0], 'set1Player1', 'set2Player1', 'set3Player1');
        helpers.displayPlayerInModal(match["players"][1], 'set1Player2', 'set2Player3', 'set3Player3');
        helpers.displayPlayerInModal(match["players"][2], 'set1Player3', 'set2Player2', 'set3Player4');
        helpers.displayPlayerInModal(match["players"][3], 'set1Player4', 'set2Player4', 'set3Player2');
        document.getElementById('set1ScoreHome').value = match["set1Home"] > 0 ? match["set1Home"] : '';
        document.getElementById('set1ScoreAway').value = match["set1Away"] > 0 ? match["set1Away"] : '';
        document.getElementById('set2ScoreHome').value = match["set2Home"] > 0 ? match["set2Home"] : '';
        document.getElementById('set2ScoreAway').value = match["set2Away"] > 0 ? match["set2Away"] : '';
        document.getElementById('set3ScoreHome').value = match["set3Home"] > 0 ? match["set3Home"] : '';
        document.getElementById('set3ScoreAway').value = match["set3Away"] > 0 ? match["set3Away"] : '';
    },
    displayPlayerInModal: function (player, pElementId1, pElementId2, pElementId3) {
        const playerElement1 = document.getElementById(pElementId1);
        playerElement1.textContent = player.firstName + ' ' + player.name;
        const playerElement2 = document.getElementById(pElementId2);
        playerElement2.textContent = player.firstName + ' ' + player.name;
        const playerElement3 = document.getElementById(pElementId3);
        playerElement3.textContent = player.firstName + ' ' + player.name;

    },
    calculateBonusPoints: function (player) {
        var bonus = 0;
        if (player.gender === 'Woman') {
            bonus += 2;
        }
        if (player.playsCompetition == 0) {
            bonus += 5;
        }
        if (player.doubleRanking > 10) {
            bonus += 4;
        } else if (player.doubleRanking > 8) {
            bonus += 3;
        } else if (player.doubleRanking > 6) {
            bonus += 2;
        } else if (player.doubleRanking > 4) {
            bonus += 1;
        }
        return bonus;
    },

    mapRoundMatch: function (match, allPlayers) {
        const matchObject = [];
        matchObject["id"] = match.id;
        matchObject["players"] = [];
        matchObject["players"].push(allPlayers.find(player => player.id === match.firstPlayer.id));
        matchObject["players"].push(allPlayers.find(player => player.id === match.secondPlayer.id));
        matchObject["players"].push(allPlayers.find(player => player.id === match.thirdPlayer.id));
        matchObject["players"].push(allPlayers.find(player => player.id === match.fourthPlayer.id));
        matchObject["set1Home"] = match.firstSet.home;
        matchObject["set1Away"] = match.firstSet.away;
        matchObject["set2Home"] = match.secondSet.home;
        matchObject["set2Away"] = match.secondSet.away;
        matchObject["set3Home"] = match.thirdSet.home;
        matchObject["set3Away"] = match.thirdSet.away;
        return matchObject;
    },
    updateAmountPlayersSpan: function (amountPlayers) {
        const span = document.getElementById('playersPresent');
        span.textContent = "Aantal spelers: " + amountPlayers;
    },

    showErrorModal: function (errors) {
        const errorModalHtml = document.getElementById('errorModal');
        const errorModal = new mdb.Modal(errorModalHtml);
        errorModalHtml.querySelector('.modal-body').innerHTML = '';
        errors.forEach(error => {
            //create p element and add error message
            const p = document.createElement('p');
            p.textContent = error;
            //add p element to modal
            errorModalHtml.querySelector('.modal-body').appendChild(p);
        });
        errorModal.show();
    },
    getScoreInputValue: function (inputId) {
        const scoreValue = document.getElementById(inputId).value;
        return scoreValue ? parseInt(scoreValue) : 0;

    }
}

export { helpers };