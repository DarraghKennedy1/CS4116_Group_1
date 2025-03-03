# CS4116_Group_1 - Business Service Marketplace

## 1. Introduction
This project involves developing a business service marketplace where businesses can advertise their services, and users can connect with businesses to negotiate and arrange services. The project will be built using HTML, CSS, Bootstrap, PHP, and MySQL.

## 2. Technical Specification

### 2.1 Simplified Technology Stack
- **Frontend**: Bootstrap 5 with basic CSS
- **JavaScript**: Vanilla JS for interactivity
- **Backend**: PHP with MySQL
- **Search**: Basic SQL search with filters
- **Email**: PHP mail() function for basic notifications

### 2.2 Simplified Database Schema
```sql
CREATE TABLE Users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE Coaches (
    coach_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    expertise VARCHAR(255),
    availability TEXT,
    FOREIGN KEY (user_id) REFERENCES Users(user_id)
);

CREATE TABLE ServiceTiers (
    tier_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE, -- e.g., Bronze, Silver, Gold, Platinum
    description TEXT,
    price DECIMAL(8,2) NOT NULL
);

CREATE TABLE Sessions (
    session_id INT PRIMARY KEY AUTO_INCREMENT,
    learner_id INT,
    coach_id INT,
    tier_id INT,
    scheduled_time DATETIME,
    status ENUM('scheduled', 'completed', 'cancelled'),
    FOREIGN KEY (learner_id) REFERENCES Users(user_id),
    FOREIGN KEY (coach_id) REFERENCES Coaches(coach_id),
    FOREIGN KEY (tier_id) REFERENCES ServiceTiers(tier_id)
);

CREATE TABLE Reviews (
    review_id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES Sessions(session_id)
);
```

### 2.3 Core API Endpoints
```php
// User Authentication
POST /auth/login
POST /auth/register

// Session Management
POST /sessions
GET /sessions/{user_id}

// Search
GET /search?q={query}
```

### 2.4 Core Features Implementation

#### 2.4.1 User Registration and Profiles
- Basic registration form
- Simple profile pages
- Role-based access (learner/coach)

#### 2.4.2 Search and Matching
- Basic search by expertise
- Simple filtering by availability

#### 2.4.3 Booking and Scheduling
- Basic calendar interface
- Simple session management
- Email notifications using PHP mail()

#### 2.4.4 Communication Tools
- Basic messaging system
- External video links (e.g., Zoom/Google Meet)

#### 2.4.5 Payment Processing
- Mock payment system
- Simple session tracking

#### 2.4.6 Review and Rating System
- Basic star rating system
- Simple review submission

### 2.5 Security Measures
- Basic input validation
- Password hashing
- Session management

### 2.6 Testing Requirements
- Basic functionality testing
- Cross-browser testing (Chrome, Firefox)
- Database integrity checks

### 2.7 Deployment Strategy
- Simple shared hosting
- Basic database backup
- Manual deployment process

## 3. Simplified Project Timeline
1. Week 1: Requirements & Database Design
2. Week 2: User Registration & Profiles
3. Week 3: Search & Booking System
4. Week 4: Messaging & Reviews
5. Week 5: Testing & Final Touches

## 4. Team Responsibilities
1. Frontend Development: [Name]
2. Backend Development: [Name]
3. Database & API: [Name]
4. Testing & Documentation: [Name]

## 5. Additional Features (Optional)
- AI-driven recommendations
- Advanced search filters
- Payment system integration
- Mobile responsiveness

## 6. Getting Started Guide

### 6.1 Initial Setup
1. **Set up version control**:
   - Create a GitHub repository
   - Set up .gitignore for PHP projects
   - Create main and development branches

2. **Local development environment**:
   - Install XAMPP/WAMP for PHP and MySQL
   - Set up database connection
   - Create basic project structure:
     ```
     /project
     ├── assets/
     │   ├── css/
     │   ├── js/
     │   └── images/
     ├── includes/
     ├── pages/
     └── index.php
     ```

### 6.2 First Week Tasks
1. **Database Setup**:
   - Create database schema
   - Populate with sample data
   - Write basic CRUD operations

2. **User Authentication**:
   - Create login/register forms
   - Implement basic session management
   - Add role-based access control

3. **Basic Frontend**:
   - Set up Bootstrap template
   - Create navigation bar
   - Add basic styling

### 6.3 Development Workflow
1. **Daily Standups**:
   - 15-minute meetings to discuss progress
   - Identify blockers
   - Assign tasks for the day

2. **Task Breakdown**:
   - Frontend: [Name] - Focus on UI components
   - Backend: [Name] - Work on API endpoints
   - Database: [Name] - Manage schema and queries
   - Testing: [Name] - Write test cases and documentation

3. **Code Reviews**:
   - Review each other's pull requests
   - Maintain coding standards
   - Ensure security best practices

### 6.4 Recommended Tools
- **Version Control**: GitHub
- **Project Management**: Trello or GitHub Projects
- **Communication**: Discord or Slack
- **Code Editor**: VS Code with PHP extensions

### 6.5 Milestones
1. **Week 1**: Basic authentication and user profiles
2. **Week 2**: Search functionality and coach listings
3. **Week 3**: Booking system and session management
4. **Week 4**: Messaging and review system
5. **Week 5**: Final testing and deployment

## 7. Branching Strategy

### 7.1 Branch Overview
- **main**: Stable production-ready code
- **feature/***: Feature development branches
- **hotfix/***: Urgent bug fixes
- **release/***: Release preparation branches

### 7.2 Feature Branch Workflow

1. **Create a new feature branch**:
```bash
git checkout -b feature/feature-name
```

2. **Develop the feature**:
```bash
# Make changes
git add .
git commit -m "Implement feature X"
```

3. **Keep updated with main**:
```bash
git checkout main
git pull origin main
git checkout feature/feature-name
git merge main
```

4. **Push your feature branch**:
```bash
git push -u origin feature/feature-name
```

5. **Create Pull Request** when ready:
   - Go to GitHub
   - Create PR from `feature/feature-name` to `main`
   - Add description and request reviews

6. **After PR is approved and merged**:
```bash
# Switch to main and pull latest
git checkout main
git pull origin main

# Delete the feature branch
git branch -d feature/feature-name
git push origin --delete feature/feature-name
```

### 7.3 Best Practices
1. **Branch Naming**:
   - Use `feature/` prefix for features
   - Use descriptive names (e.g., `feature/user-auth`)
   - Keep names short but meaningful

2. **Commit Messages**:
   - Use present tense
   - Be specific (e.g., "Add user authentication form")

3. **Branch Management**:
   - Keep branches focused on single features
   - Delete merged branches
   - Regularly update with main

4. **Code Reviews**:
   - Create PRs for all changes
   - Review each other's code
   - Use GitHub's review tools

### 7.4 Common Commands
```bash
# Create and switch to new feature branch
git checkout -b feature/feature-name

# Push feature branch to remote
git push -u origin feature/feature-name

# Update feature branch with main
git checkout main
git pull origin main
git checkout feature/feature-name
git merge main

# Delete local feature branch
git branch -d feature/feature-name

# Delete remote feature branch
git push origin --delete feature/feature-name
```

### 7.5 Example Workflow
1. Start new feature:
```bash
git checkout -b feature/user-auth
```

2. Make changes and commit:
```bash
git add .
git commit -m "Add user authentication form"
```

3. Push and create PR:
```bash
git push -u origin feature/user-auth
```

4. After PR is merged:
```bash
git checkout main
git pull origin main
git branch -d feature/user-auth
git push origin --delete feature/user-auth
```
