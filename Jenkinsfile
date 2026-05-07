pipeline {
    agent any

    environment {
        GIT_REPO = 'https://github.com/vanshikapandit-cyber/jenkins-fullstack.git'
        GIT_CREDENTIALS = 'github_creds'
    }

    stages {
        stage('Checkout') {
            steps {
                git url: "${GIT_REPO}", branch: 'main'
            }
        }

        stage('Clean Previous Builds') {
            steps {
                bat 'IF EXIST encrypted_output (rmdir /s /q encrypted_output)'
            }
        }

        stage('Install Dependencies') {
            steps {
                dir('Backend') {
                    bat 'npm install'
                }
            }
        }

        stage('Encrypt Backend') {
            steps {
                bat '''
                if not exist encrypted_output\\backend mkdir encrypted_output\\backend
                npx javascript-obfuscator Backend --output encrypted_output/backend
                '''
            }
        }

        stage('Encrypt Frontend') {
            steps {
                bat '''
                xcopy Frontend encrypted_output\\frontend /E /I /Y
                '''
            }
        }

        stage('Push to Encrypted Branch') {
            steps {
                withCredentials([usernamePassword(credentialsId: "${GIT_CREDENTIALS}", usernameVariable: 'GIT_USER', passwordVariable: 'GIT_TOKEN')]) {
                    dir('encrypted_output') {
                        bat 'git init'
                        bat 'git config user.name "jenkins"'
                        bat 'git config user.email "jenkins@gmail.com"'
                        bat 'git checkout -b encrypted'
                        bat 'git remote add origin %GIT_REPO%'
                        bat 'git add .'
                        bat 'git commit -m "Encrypted Build"'
                        
                        script {
                            def pushUrl = env.GIT_REPO.replace("https://", "https://${GIT_TOKEN}@")
                            bat "git push --force ${pushUrl} encrypted"
                        }
                    }
                }
            }
        }
    }
}