/**
 * OCMS Quiz Scoring Module
 *
 * Handles quiz submission, scoring, and feedback display for auto-generated quizzes.
 * Loaded as external script to comply with Content Security Policy.
 *
 * Usage: Include this script after the quiz HTML:
 *   <script src="/ocms-service/js/ocms-quiz.js"></script>
 *
 * The quiz form should have:
 *   - id="ocms-quiz-form" with onsubmit="return ocmsScoreQuiz(event);"
 *   - .ocms-question elements with data-question, data-correct, data-explanation attributes
 *   - .ocms-feedback div inside each question for showing results
 *   - #ocms-quiz-results, #ocms-score-display, #ocms-correct-count for summary
 */

(function() {
    'use strict';

    /**
     * Score the quiz and display feedback
     * @param {Event} event - Form submit event
     * @returns {boolean} false to prevent form submission
     */
    window.ocmsScoreQuiz = function(event) {
        event.preventDefault();

        var questions = document.querySelectorAll('.ocms-question');
        var correctCount = 0;
        var totalQuestions = questions.length;

        questions.forEach(function(q) {
            var qNum = q.getAttribute('data-question');
            var correctAnswer = q.getAttribute('data-correct');
            var explanation = q.getAttribute('data-explanation');
            var feedbackDiv = q.querySelector('.ocms-feedback');
            var selectedInput = q.querySelector('input[name="q' + qNum + '"]:checked');

            if (!selectedInput) return;

            var userAnswer = selectedInput.value;
            var isCorrect = userAnswer === correctAnswer;

            if (isCorrect) {
                correctCount++;
                feedbackDiv.style.background = '#d4edda';
                feedbackDiv.style.color = '#155724';
                feedbackDiv.innerHTML = '<strong>Correct!</strong> ' + explanation;
            } else {
                feedbackDiv.style.background = '#f8d7da';
                feedbackDiv.style.color = '#721c24';
                feedbackDiv.innerHTML = '<strong>Incorrect.</strong> ' + explanation;
            }
            feedbackDiv.style.display = 'block';

            // Highlight selected answer
            q.querySelectorAll('label').forEach(function(label) {
                label.style.borderColor = '#e0e0e0';
                label.style.background = 'white';
            });
            selectedInput.parentElement.style.borderColor = isCorrect ? '#27ae60' : '#e74c3c';
            selectedInput.parentElement.style.background = isCorrect ? '#eafaf1' : '#fdedec';
        });

        var score = Math.round((correctCount / totalQuestions) * 100);

        // Update results display
        var scoreDisplay = document.getElementById('ocms-score-display');
        var correctCountDisplay = document.getElementById('ocms-correct-count');
        var resultsDiv = document.getElementById('ocms-quiz-results');
        var submitBtn = document.getElementById('ocms-submit-quiz');

        if (scoreDisplay) scoreDisplay.textContent = score;
        if (correctCountDisplay) correctCountDisplay.textContent = correctCount;
        if (resultsDiv) resultsDiv.style.display = 'block';

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Quiz Submitted';
            submitBtn.style.background = '#95a5a6';
        }

        // Disable all inputs
        document.querySelectorAll('#ocms-quiz-form input').forEach(function(input) {
            input.disabled = true;
        });

        // Record score if RecordTest function exists (OCMS tracking integration)
        if (typeof RecordTest === 'function') {
            RecordTest(score);
        }

        // Scroll to results
        if (resultsDiv) {
            resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        return false;
    };

    // Auto-initialize if DOM is already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            // Quiz is ready
        });
    }
})();
